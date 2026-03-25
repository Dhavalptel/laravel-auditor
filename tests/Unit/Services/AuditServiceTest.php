<?php

declare(strict_types=1);

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Resolvers\UserResolver;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;

/**
 * Unit tests for AuditService.
 *
 * Covers shouldAudit logic, attribute exclusion, tag resolution,
 * queue dispatch, and synchronous write behaviour.
 */

describe('AuditService', function () {

    beforeEach(function () {
        $this->resolver = Mockery::mock(UserResolver::class);
        $this->resolver->shouldReceive('resolve')->andReturn(null)->byDefault();
        $this->service  = new AuditService($this->resolver);
    });

    // ─── shouldAudit ────────────────────────────────────────────────────────

    it('returns false when auditing is disabled for the Audit model itself', function () {
        $audit = Mockery::mock(Audit::class)->makePartial();

        expect($this->service->shouldAudit($audit, AuditEvent::Created))->toBeFalse();
    });

    it('returns false for models in the exclude_models config list', function () {
        config(['auditor.exclude_models' => [ExcludedModel::class]]);

        $model = new ExcludedModel();

        expect($this->service->shouldAudit($model, AuditEvent::Created))->toBeFalse();
    });

    it('returns false for read events when read tracking is disabled', function () {
        config(['auditor.events.read' => false]);

        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');

        expect($this->service->shouldAudit($model, AuditEvent::Read))->toBeFalse();
    });

    it('returns true for read events when read tracking is enabled', function () {
        config(['auditor.events.read' => true]);
        config(['auditor.exclude_models' => []]);

        $model = Mockery::mock(Model::class)->makePartial();

        expect($this->service->shouldAudit($model, AuditEvent::Read))->toBeTrue();
    });

    it('returns true for any regular model not in the exclusion list', function () {
        config(['auditor.exclude_models' => []]);

        $model = Mockery::mock(Model::class)->makePartial();

        expect($this->service->shouldAudit($model, AuditEvent::Created))->toBeTrue();
    });

    // ─── writeSync ──────────────────────────────────────────────────────────

    it('writes an audit record to the database synchronously', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Created,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: null,
            userId: null,
            oldValues: [],
            newValues: ['name' => 'Alice'],
            ipAddress: null,
            userAgent: null,
            url: null,
            tags: [],
            occurredAt: new \DateTimeImmutable(),
        );

        $audit = $this->service->writeSync($dto);

        expect($audit)->toBeInstanceOf(Audit::class)
            ->and($audit->event)->toBe(AuditEvent::Created)
            ->and($audit->auditable_type)->toBe('App\\Models\\User')
            ->and($audit->new_values)->toBe(['name' => 'Alice'])
            ->and($audit->exists)->toBeTrue();
    });

    // ─── Queue dispatch ──────────────────────────────────────────────────────

    it('dispatches a queued job when queue is enabled', function () {
        Queue::fake();
        config(['auditor.queue.enabled' => true]);

        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');
        $model->shouldReceive('getKey')->andReturn(1);
        $model->shouldReceive('getAttributes')->andReturn(['id' => 1]);
        $model->shouldReceive('getDirty')->andReturn([]);
        $model->shouldReceive('getOriginal')->andReturn([]);

        config(['auditor.exclude_models' => []]);

        $this->service->record($model, AuditEvent::Created);

        Queue::assertPushed(\DevToolbox\Auditor\Jobs\WriteAuditJob::class);
    });

    it('writes synchronously when queue is disabled', function () {
        config(['auditor.queue.enabled' => false]);
        config(['auditor.exclude_models' => []]);

        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');
        $model->shouldReceive('getKey')->andReturn(1);
        $model->shouldReceive('getAttributes')->andReturn(['id' => 1, 'name' => 'Bob']);
        $model->shouldReceive('getDirty')->andReturn([]);
        $model->shouldReceive('getOriginal')->andReturn([]);

        $this->service->record($model, AuditEvent::Created);

        expect(Audit::count())->toBe(1);
    });

    it('does not throw when an exception occurs during record', function () {
        config(['auditor.exclude_models' => []]);
        config(['auditor.queue.enabled' => false]);

        // Model that throws during attribute capture
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');
        $model->shouldReceive('getKey')->andReturn(1);
        $model->shouldReceive('getAttributes')->andThrow(new \RuntimeException('DB error'));
        $model->shouldReceive('getDirty')->andReturn([]);
        $model->shouldReceive('getOriginal')->andReturn([]);

        // Should not throw — audit failures are silent
        expect(fn () => $this->service->record($model, AuditEvent::Created))->not->toThrow(\Throwable::class);
    });

    // ─── Fluent builder factory methods ─────────────────────────────────────

    it('newActivity() returns an ActivityBuilder instance', function () {
        $builder = $this->service->newActivity();

        expect($builder)->toBeInstanceOf(\DevToolbox\Auditor\Builders\ActivityBuilder::class);
    });

    it('inLog() returns an ActivityBuilder pre-set with the log name', function () {
        $builder = $this->service->inLog('auth');

        expect($builder)->toBeInstanceOf(\DevToolbox\Auditor\Builders\ActivityBuilder::class);

        // Confirm the log name was set by writing a record
        $audit = $builder->log('test');
        expect($audit->log_name)->toBe('auth');
    });

    it('causedBy() returns an ActivityBuilder', function () {
        $model = makeModel();
        $builder = $this->service->causedBy($model);

        expect($builder)->toBeInstanceOf(\DevToolbox\Auditor\Builders\ActivityBuilder::class);
    });

    it('performedOn() returns an ActivityBuilder', function () {
        $model = makeModel();
        $builder = $this->service->performedOn($model);

        expect($builder)->toBeInstanceOf(\DevToolbox\Auditor\Builders\ActivityBuilder::class);
    });

    it('withProperties() returns an ActivityBuilder', function () {
        $builder = $this->service->withProperties(['key' => 'val']);

        expect($builder)->toBeInstanceOf(\DevToolbox\Auditor\Builders\ActivityBuilder::class);
    });

    // ─── Model swapping ──────────────────────────────────────────────────────

    it('writeSync() uses the configured audit_model class', function () {
        config(['auditor.audit_model' => Audit::class]);

        $dto = new \DevToolbox\Auditor\DTOs\AuditEventDTO(
            event: AuditEvent::Created,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: null,
            userId: null,
            oldValues: [],
            newValues: ['name' => 'Alice'],
            ipAddress: null,
            userAgent: null,
            url: null,
            tags: [],
            occurredAt: new \DateTimeImmutable(),
        );

        $result = $this->service->writeSync($dto);
        expect($result)->toBeInstanceOf(Audit::class);
    });

    it('shouldAudit() returns false for the configured custom audit model', function () {
        config(['auditor.audit_model' => Audit::class]);

        $audit = Mockery::mock(Audit::class)->makePartial();
        expect($this->service->shouldAudit($audit, AuditEvent::Created))->toBeFalse();
    });

});

/**
 * Anonymous model class used to test the exclusion list.
 */
class ExcludedModel extends \Illuminate\Database\Eloquent\Model {}

<?php

declare(strict_types=1);

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Jobs\WriteAuditJob;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Services\AuditService;

/**
 * Feature tests for WriteAuditJob.
 */

describe('WriteAuditJob', function () {

    it('writes an audit record when the job is handled', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Updated,
            auditableType: 'App\\Models\\Order',
            auditableId: '7',
            userType: 'App\\Models\\User',
            userId: 3,
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'shipped'],
            ipAddress: '192.168.1.1',
            userAgent: 'TestAgent',
            url: 'http://localhost/orders/7',
            tags: ['orders'],
            occurredAt: new \DateTimeImmutable(),
        );

        $job = new WriteAuditJob($dto);
        $job->handle(app(AuditService::class));

        expect(Audit::count())->toBe(1);

        $audit = Audit::first();
        expect($audit->event)->toBe(AuditEvent::Updated)
            ->and($audit->auditable_type)->toBe('App\\Models\\Order')
            ->and($audit->auditable_id)->toBe('7')
            ->and($audit->old_values)->toBe(['status' => 'pending'])
            ->and($audit->new_values)->toBe(['status' => 'shipped']);
    });

    it('generates a unique id from DTO fields', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Created,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: null,
            userId: null,
            oldValues: [],
            newValues: [],
            ipAddress: null,
            userAgent: null,
            url: null,
            tags: [],
            occurredAt: new \DateTimeImmutable('2024-01-01 00:00:00'),
        );

        $job = new WriteAuditJob($dto);

        expect($job->uniqueId())->toContain('App\\Models\\User')
            ->and($job->uniqueId())->toContain('created');
    });

    it('has the correct retry and backoff configuration', function () {
        config(['auditor.queue.tries' => 5, 'auditor.queue.backoff' => [5, 15]]);

        $dto = new AuditEventDTO(
            event: AuditEvent::Read,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: null,
            userId: null,
            oldValues: [],
            newValues: [],
            ipAddress: null,
            userAgent: null,
            url: null,
            tags: [],
            occurredAt: new \DateTimeImmutable(),
        );

        $job = new WriteAuditJob($dto);

        expect($job->tries)->toBe(5)
            ->and($job->backoff)->toBe([5, 15]);
    });
});

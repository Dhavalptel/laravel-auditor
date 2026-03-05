<?php

declare(strict_types=1);

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit tests for AuditEventDTO.
 *
 * Tests construction, value diff resolution, serialisation, and
 * attribute filtering logic.
 */

describe('AuditEventDTO', function () {

    /**
     * Creates a partial model mock with controllable attribute state.
     */
    function mockModel(
        string $morphClass = 'App\\Models\\User',
        mixed $key = 1,
        array $attributes = ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com'],
        array $dirty = [],
        array $original = [],
    ): Model {
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('getMorphClass')->andReturn($morphClass);
        $model->shouldReceive('getKey')->andReturn($key);
        $model->shouldReceive('getAttributes')->andReturn($attributes);
        $model->shouldReceive('getDirty')->andReturn($dirty);
        $model->shouldReceive('getOriginal')->andReturn($original);

        return $model;
    }

    it('constructs correctly with all fields', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Created,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: null,
            userId: null,
            oldValues: [],
            newValues: ['name' => 'Alice'],
            ipAddress: '127.0.0.1',
            userAgent: 'TestAgent/1.0',
            url: 'http://localhost/users',
            tags: ['users'],
            occurredAt: new \DateTimeImmutable('2024-01-01 12:00:00'),
        );

        expect($dto->event)->toBe(AuditEvent::Created)
            ->and($dto->auditableType)->toBe('App\\Models\\User')
            ->and($dto->auditableId)->toBe('1')
            ->and($dto->newValues)->toBe(['name' => 'Alice'])
            ->and($dto->tags)->toBe(['users']);
    });

    it('captures new_values for created events', function () {
        $model = mockModel(attributes: ['id' => 1, 'name' => 'Alice']);

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Created, null);

        expect($dto->newValues)->toHaveKey('name', 'Alice')
            ->and($dto->oldValues)->toBeEmpty();
    });

    it('captures old and new values for updated events', function () {
        $model = mockModel(
            attributes: ['id' => 1, 'name' => 'Bob'],
            dirty: ['name' => 'Bob'],
            original: ['name' => 'Alice'],
        );

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Updated, null);

        expect($dto->oldValues)->toHaveKey('name', 'Alice')
            ->and($dto->newValues)->toHaveKey('name', 'Bob');
    });

    it('captures old_values only for deleted events', function () {
        $model = mockModel(attributes: ['id' => 1, 'name' => 'Alice']);

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Deleted, null);

        expect($dto->oldValues)->toHaveKey('name', 'Alice')
            ->and($dto->newValues)->toBeEmpty();
    });

    it('captures no values for read events', function () {
        $model = mockModel(attributes: ['id' => 1, 'name' => 'Alice']);

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Read, null);

        expect($dto->oldValues)->toBeEmpty()
            ->and($dto->newValues)->toBeEmpty();
    });

    it('excludes specified attributes from captured values', function () {
        $model = mockModel(attributes: ['id' => 1, 'name' => 'Alice', 'password' => 'secret']);

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Created, null, except: ['password']);

        expect($dto->newValues)->not->toHaveKey('password')
            ->and($dto->newValues)->toHaveKey('name');
    });

    it('serialises to correct array structure', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Updated,
            auditableType: 'App\\Models\\Post',
            auditableId: '42',
            userType: 'App\\Models\\User',
            userId: 7,
            oldValues: ['title' => 'Old'],
            newValues: ['title' => 'New'],
            ipAddress: '10.0.0.1',
            userAgent: 'Mozilla/5.0',
            url: 'http://localhost/posts/42',
            tags: ['content'],
            occurredAt: new \DateTimeImmutable('2024-06-15 10:30:00'),
        );

        $array = $dto->toArray();

        expect($array['event'])->toBe('updated')
            ->and($array['auditable_type'])->toBe('App\\Models\\Post')
            ->and($array['auditable_id'])->toBe('42')
            ->and($array['user_type'])->toBe('App\\Models\\User')
            ->and($array['user_id'])->toBe('7')
            ->and($array['old_values'])->toBe(['title' => 'Old'])
            ->and($array['new_values'])->toBe(['title' => 'New'])
            ->and($array['ip_address'])->toBe('10.0.0.1')
            ->and($array['tags'])->toBe(['content'])
            ->and($array['created_at'])->toBe('2024-06-15 10:30:00');
    });

    it('sets old/new values to null when empty in serialised array', function () {
        $model = mockModel(attributes: ['id' => 1]);

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Read, null);
        $array = $dto->toArray();

        expect($array['old_values'])->toBeNull()
            ->and($array['new_values'])->toBeNull()
            ->and($array['tags'])->toBeNull();
    });

    it('stores tags from fromModel', function () {
        $model = mockModel();

        $dto = AuditEventDTO::fromModel($model, AuditEvent::Created, null, tags: ['billing', 'admin']);

        expect($dto->tags)->toBe(['billing', 'admin']);
    });

    it('casts userId to string in toArray', function () {
        $dto = new AuditEventDTO(
            event: AuditEvent::Created,
            auditableType: 'App\\Models\\User',
            auditableId: '1',
            userType: 'App\\Models\\Admin',
            userId: 99,
            oldValues: [],
            newValues: [],
            ipAddress: null,
            userAgent: null,
            url: null,
            tags: [],
            occurredAt: new \DateTimeImmutable(),
        );

        expect($dto->toArray()['user_id'])->toBe('99');
    });
});

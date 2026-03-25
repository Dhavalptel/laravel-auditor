<?php

declare(strict_types=1);

use DevToolbox\Auditor\DTOs\ActivityEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;

/**
 * Unit tests for ActivityEventDTO.
 *
 * Validates the fromBuilder() factory, toArray() serialisation,
 * and handling of null subject/causer.
 */

it('builds from builder state correctly via fromBuilder()', function () {
    $subject = makeModel('App\\Models\\Post', 42);
    $causer  = makeModel('App\\Models\\User', 7);

    $dto = ActivityEventDTO::fromBuilder(
        logName: 'billing',
        description: 'Invoice created',
        subject: $subject,
        causer: $causer,
        properties: ['amount' => 500],
    );

    expect($dto->logName)->toBe('billing');
    expect($dto->description)->toBe('Invoice created');
    expect($dto->auditableType)->toBe('App\\Models\\Post');
    expect($dto->auditableId)->toBe('42');
    expect($dto->causerType)->toBe('App\\Models\\User');
    expect($dto->causerId)->toBe(7);
    expect($dto->properties)->toBe(['amount' => 500]);
    expect($dto->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
});

it('handles null subject and causer gracefully', function () {
    $dto = ActivityEventDTO::fromBuilder(
        logName: 'default',
        description: 'Anonymous event',
        subject: null,
        causer: null,
        properties: [],
    );

    expect($dto->auditableType)->toBeNull();
    expect($dto->auditableId)->toBeNull();
    expect($dto->causerType)->toBeNull();
    expect($dto->causerId)->toBeNull();
});

it('toArray() maps all columns correctly', function () {
    $dto = ActivityEventDTO::fromBuilder(
        logName: 'auth',
        description: 'User logged in',
        subject: null,
        causer: null,
        properties: ['ip' => '127.0.0.1'],
    );

    $array = $dto->toArray();

    expect($array)->toHaveKey('event', AuditEvent::Activity->value);
    expect($array)->toHaveKey('log_name', 'auth');
    expect($array)->toHaveKey('description', 'User logged in');
    expect($array)->toHaveKey('auditable_type', null);
    expect($array)->toHaveKey('auditable_id', null);
    expect($array)->toHaveKey('causer_type', null);
    expect($array)->toHaveKey('causer_id', null);
    expect($array)->toHaveKey('properties', ['ip' => '127.0.0.1']);
    expect($array)->toHaveKey('user_type', null);
    expect($array)->toHaveKey('user_id', null);
    expect($array)->toHaveKey('old_values', null);
    expect($array)->toHaveKey('new_values', null);
    expect($array)->toHaveKey('tags', null);
    expect($array)->toHaveKey('created_at');
});

it('toArray() sets properties to null when the array is empty', function () {
    $dto = ActivityEventDTO::fromBuilder(
        logName: 'default',
        description: 'No props',
        subject: null,
        causer: null,
        properties: [],
    );

    expect($dto->toArray()['properties'])->toBeNull();
});

it('toArray() sets event to the activity enum value', function () {
    $dto = ActivityEventDTO::fromBuilder(
        logName: 'default',
        description: 'test',
        subject: null,
        causer: null,
        properties: [],
    );

    expect($dto->toArray()['event'])->toBe('activity');
});

it('causer_id is cast to string in toArray()', function () {
    $causer = makeModel('App\\Models\\User', 99);

    $dto = ActivityEventDTO::fromBuilder(
        logName: 'default',
        description: 'test',
        subject: null,
        causer: $causer,
        properties: [],
    );

    expect($dto->toArray()['causer_id'])->toBe('99');
});

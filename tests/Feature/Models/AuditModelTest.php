<?php

declare(strict_types=1);

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Services\AuditService;

/**
 * Feature tests for Audit model query scopes.
 *
 * Verifies that each scope produces the correct SQL filter
 * and returns the expected subset of records.
 */

/**
 * Helper: create an audit record directly in the DB.
 */
function seedAudit(array $overrides = []): Audit
{
    return Audit::create(array_merge([
        'event'          => AuditEvent::Created->value,
        'auditable_type' => 'App\\Models\\User',
        'auditable_id'   => '1',
        'user_type'      => null,
        'user_id'        => null,
        'old_values'     => null,
        'new_values'     => null,
        'ip_address'     => null,
        'user_agent'     => null,
        'url'            => null,
        'tags'           => null,
        'created_at'     => now()->toDateTimeString(),
    ], $overrides));
}

describe('Audit model scopes', function () {

    // ─── scopeForModel ───────────────────────────────────────────────────────

    describe('scopeForModel', function () {

        it('returns audits matching auditable_type and auditable_id', function () {
            $userAudit = seedAudit(['auditable_type' => 'App\\Models\\User', 'auditable_id' => '1']);
            $postAudit = seedAudit(['auditable_type' => 'App\\Models\\Post', 'auditable_id' => '5']);

            $model = Mockery::mock(\Illuminate\Database\Eloquent\Model::class)->makePartial();
            $model->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');
            $model->shouldReceive('getKey')->andReturn(1);

            $results = Audit::forModel($model)->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->id)->toBe($userAudit->id);
        });
    });

    // ─── scopeByUser ─────────────────────────────────────────────────────────

    describe('scopeByUser', function () {

        it('returns audits by a specific user', function () {
            seedAudit(['user_type' => 'App\\Models\\User', 'user_id' => '42']);
            seedAudit(['user_type' => 'App\\Models\\User', 'user_id' => '99']);

            $user = Mockery::mock(\Illuminate\Database\Eloquent\Model::class)->makePartial();
            $user->shouldReceive('getMorphClass')->andReturn('App\\Models\\User');
            $user->shouldReceive('getKey')->andReturn(42);

            $results = Audit::byUser($user)->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->user_id)->toBe('42');
        });
    });

    // ─── scopeEvent ──────────────────────────────────────────────────────────

    describe('scopeEvent', function () {

        it('filters by a single event type', function () {
            seedAudit(['event' => 'created']);
            seedAudit(['event' => 'updated']);
            seedAudit(['event' => 'deleted']);

            $results = Audit::event(AuditEvent::Updated)->get();

            expect($results)->toHaveCount(1)
                ->and($results->first()->event)->toBe(AuditEvent::Updated);
        });

        it('filters by multiple event types', function () {
            seedAudit(['event' => 'created']);
            seedAudit(['event' => 'updated']);
            seedAudit(['event' => 'deleted']);

            $results = Audit::event(AuditEvent::Created, AuditEvent::Deleted)->get();

            expect($results)->toHaveCount(2);
        });

        it('accepts string values as event types', function () {
            seedAudit(['event' => 'read']);
            seedAudit(['event' => 'updated']);

            $results = Audit::event('read')->get();

            expect($results)->toHaveCount(1);
        });
    });

    // ─── scopeWithinDays ──────────────────────────────────────────────────────

    describe('scopeWithinDays', function () {

        it('returns only recent records within the given days', function () {
            seedAudit(['created_at' => now()->subDays(2)->toDateTimeString()]);
            seedAudit(['created_at' => now()->subDays(10)->toDateTimeString()]);

            $results = Audit::withinDays(7)->get();

            expect($results)->toHaveCount(1);
        });
    });

    // ─── scopeBetweenDates ───────────────────────────────────────────────────

    describe('scopeBetweenDates', function () {

        it('returns records within the specified date range', function () {
            seedAudit(['created_at' => '2024-03-01 12:00:00']);
            seedAudit(['created_at' => '2024-06-15 12:00:00']);
            seedAudit(['created_at' => '2024-12-01 12:00:00']);

            $results = Audit::betweenDates(
                new \DateTime('2024-03-01'),
                new \DateTime('2024-07-01')
            )->get();

            expect($results)->toHaveCount(2);
        });
    });

    // ─── scopeWithTag ────────────────────────────────────────────────────────

    describe('scopeWithTag', function () {

        it('returns records with the specified tag', function () {
            seedAudit(['tags' => ['billing', 'admin']]);
            seedAudit(['tags' => ['users']]);
            seedAudit(['tags' => null]);

            $results = Audit::withTag('billing')->get();

            expect($results)->toHaveCount(1);
        });
    });

    // ─── scopeWhereAttributeChanged ──────────────────────────────────────────

    describe('scopeWhereAttributeChanged', function () {

        it('returns records where a specific attribute was changed', function () {
            seedAudit(['new_values' => ['name' => 'Bob', 'email' => 'bob@test.com']]);
            seedAudit(['new_values' => ['email' => 'alice@test.com']]);
            seedAudit(['new_values' => null]);

            $results = Audit::whereAttributeChanged('name')->get();

            expect($results)->toHaveCount(1);
        });
    });

    // ─── diff() helper ───────────────────────────────────────────────────────

    describe('Audit::diff()', function () {

        it('returns a structured diff of old and new values', function () {
            $audit = seedAudit([
                'old_values' => ['name' => 'Alice', 'role' => 'user'],
                'new_values' => ['name' => 'Bob', 'role' => 'user'],
            ]);

            $diff = $audit->diff();

            expect($diff)->toHaveKey('name')
                ->and($diff['name']['old'])->toBe('Alice')
                ->and($diff['name']['new'])->toBe('Bob')
                ->and($diff['role']['old'])->toBe('user')
                ->and($diff['role']['new'])->toBe('user');
        });

        it('handles null old or new values gracefully', function () {
            $audit = seedAudit(['old_values' => null, 'new_values' => ['name' => 'Alice']]);

            $diff = $audit->diff();

            expect($diff)->toHaveKey('name')
                ->and($diff['name']['old'])->toBeNull()
                ->and($diff['name']['new'])->toBe('Alice');
        });
    });

    // ─── ULID generation ────────────────────────────────────────────────────

    describe('ULID primary key', function () {

        it('auto-assigns a ULID when creating an audit record', function () {
            $audit = seedAudit();

            expect($audit->id)->toBeString()
                ->and(strlen($audit->id))->toBe(26);
        });

        it('respects a manually provided ULID', function () {
            $ulid = (string) \Illuminate\Support\Str::ulid();
            $audit = seedAudit(['id' => $ulid]);

            expect($audit->id)->toBe($ulid);
        });
    });
});

<?php

declare(strict_types=1);

use DevToolbox\Auditor\Models\Audit;

/**
 * Feature tests for the `auditor:prune` Artisan command.
 */

/**
 * Seed a minimal audit record.
 */
function seedAuditForPrune(string $createdAt): Audit
{
    return Audit::create([
        'event'          => 'created',
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
        'created_at'     => $createdAt,
    ]);
}

describe('auditor:prune command', function () {

    it('prunes records older than the specified days', function () {
        seedAuditForPrune(now()->subDays(100)->toDateTimeString()); // old
        seedAuditForPrune(now()->subDays(5)->toDateTimeString());   // recent

        $this->artisan('auditor:prune', ['--days' => 90])
            ->assertExitCode(0);

        expect(Audit::count())->toBe(1);
    });

    it('prunes only the specified event type', function () {
        seedAuditForPrune(now()->subDays(100)->toDateTimeString()); // created, old
        Audit::query()->update(['event' => 'read']); // change to read

        // Add a created event that's also old
        seedAuditForPrune(now()->subDays(100)->toDateTimeString()); // created, old

        $this->artisan('auditor:prune', ['--days' => 90, '--event' => 'created'])
            ->assertExitCode(0);

        // Only the 'created' record should be pruned
        expect(Audit::where('event', 'created')->count())->toBe(0);
        expect(Audit::where('event', 'read')->count())->toBe(1);
    });

    it('outputs how many records would be deleted in dry-run mode', function () {
        seedAuditForPrune(now()->subDays(100)->toDateTimeString());
        seedAuditForPrune(now()->subDays(200)->toDateTimeString());

        $this->artisan('auditor:prune', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        // Dry run should not delete anything
        expect(Audit::count())->toBe(2);
    });

    it('reports zero records when nothing matches', function () {
        seedAuditForPrune(now()->subDays(5)->toDateTimeString()); // recent — not prunable

        $this->artisan('auditor:prune', ['--days' => 90])
            ->expectsOutputToContain('No audit records found')
            ->assertExitCode(0);

        expect(Audit::count())->toBe(1);
    });

    it('returns failure when days is zero or negative', function () {
        $this->artisan('auditor:prune', ['--days' => 0])
            ->assertExitCode(1);
    });

    it('returns failure for an invalid event type', function () {
        $this->artisan('auditor:prune', ['--days' => 30, '--event' => 'invalid_event'])
            ->assertExitCode(1);
    });
});

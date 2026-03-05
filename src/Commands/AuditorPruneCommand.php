<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Commands;

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Models\Audit;
use Illuminate\Console\Command;

/**
 * Artisan command to prune old audit records from the database.
 *
 * For tables with millions of rows, pruning is done in chunks
 * to avoid locking the table and overwhelming the database.
 *
 * Usage:
 *
 *   # Prune all audits older than 90 days (default)
 *   php artisan auditor:prune
 *
 *   # Prune audits older than 30 days
 *   php artisan auditor:prune --days=30
 *
 *   # Prune only 'read' events older than 7 days
 *   php artisan auditor:prune --days=7 --event=read
 *
 *   # Dry run — show how many records would be deleted
 *   php artisan auditor:prune --dry-run
 *
 * Schedule in your console kernel:
 *
 *   $schedule->command('auditor:prune --days=90')->daily();
 */
class AuditorPruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auditor:prune
        {--days=90      : Delete records older than this many days}
        {--event=       : Only prune a specific event type (created|read|updated|deleted|restored)}
        {--chunk=1000   : Number of records to delete per chunk}
        {--dry-run      : Preview how many records would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune audit records older than a specified number of days.';

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 = success, 1 = failure).
     */
    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $event   = $this->option('event');
        $chunk   = (int) $this->option('chunk');
        $dryRun  = (bool) $this->option('dry-run');

        if ($days <= 0) {
            $this->error('The --days option must be a positive integer.');
            return self::FAILURE;
        }

        if ($event && ! in_array($event, AuditEvent::values(), true)) {
            $this->error("Invalid event type '{$event}'. Valid values: " . implode(', ', AuditEvent::values()));
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = Audit::query()->where('created_at', '<', $cutoff);

        if ($event) {
            $query->where('event', $event);
        }

        $total = $query->count();

        if ($dryRun) {
            $this->info("[Dry Run] Would delete {$total} audit record(s) older than {$days} day(s)" . ($event ? " with event '{$event}'" : '') . '.');
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('No audit records found matching the criteria.');
            return self::SUCCESS;
        }

        $this->info("Pruning {$total} audit record(s) older than {$days} day(s)" . ($event ? " with event '{$event}'" : '') . '...');

        $deleted = 0;
        $bar     = $this->output->createProgressBar($total);
        $bar->start();

        // Chunk deletes to avoid full-table locks on large datasets
        do {
            $ids = (clone $query)->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $count    = Audit::whereIn('id', $ids)->delete();
            $deleted += $count;

            $bar->advance($count);
        } while ($ids->count() === $chunk);

        $bar->finish();
        $this->newLine();
        $this->info("✓ Pruned {$deleted} audit record(s) successfully.");

        return self::SUCCESS;
    }
}

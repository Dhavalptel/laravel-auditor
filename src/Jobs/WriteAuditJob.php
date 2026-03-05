<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Jobs;

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job for writing an audit record asynchronously.
 *
 * When `auditor.queue.enabled` is true, the AuditService dispatches
 * this job instead of writing directly. The job carries the fully
 * resolved AuditEventDTO and delegates the actual write to AuditService.
 *
 * Configuration options (in auditor.php):
 *
 * ```php
 * 'queue' => [
 *     'enabled'    => true,
 *     'connection' => 'redis',    // null = default connection
 *     'queue'      => 'audits',   // dedicated queue name
 *     'tries'      => 3,
 *     'backoff'    => [10, 30],   // exponential backoff in seconds
 * ],
 * ```
 *
 * Recommended: run a dedicated queue worker for the 'audits' queue
 * to avoid audit writes competing with business-critical jobs.
 *
 *   php artisan queue:work --queue=audits
 */
class WriteAuditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public int $tries;

    /**
     * Delay in seconds between retry attempts (supports arrays for backoff).
     *
     * @var int[]
     */
    public array $backoff;

    /**
     * @param  AuditEventDTO  $dto  The fully resolved audit event payload.
     */
    public function __construct(
        public readonly AuditEventDTO $dto,
    ) {
        $this->tries   = config('auditor.queue.tries', 3);
        $this->backoff = config('auditor.queue.backoff', [10, 30]);
    }

    /**
     * Executes the job and writes the audit record to the database.
     *
     * @param  AuditService  $auditService  Resolved from the container.
     * @return void
     */
    public function handle(AuditService $auditService): void
    {
        $auditService->writeSync($this->dto);
    }

    /**
     * Returns a unique ID for deduplication (optional, if using unique jobs).
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return implode(':', [
            $this->dto->auditableType,
            $this->dto->auditableId,
            $this->dto->event->value,
            $this->dto->occurredAt->getTimestamp(),
        ]);
    }
}

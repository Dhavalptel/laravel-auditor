<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Services;

use DevToolbox\Auditor\Contracts\Auditable;
use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Jobs\WriteAuditJob;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Resolvers\UserResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Core service responsible for handling all audit event processing.
 *
 * This service is the single point of entry for recording audit events.
 * It decides whether a model should be audited, resolves user context,
 * constructs the DTO, and either writes synchronously or dispatches
 * to a queue based on the package configuration.
 *
 * You should not call this class directly in most cases. The
 * GlobalModelObserver hooks into Eloquent events and delegates here.
 */
class AuditService
{
    /**
     * @param  UserResolver  $userResolver  Injected user resolution strategy.
     */
    public function __construct(
        protected readonly UserResolver $userResolver,
    ) {}

    /**
     * Records an audit event for the given model.
     *
     * This is the primary entry point called by the observer.
     * It validates whether the model should be audited, builds
     * the DTO, and routes it to sync or async storage.
     *
     * @param  Model       $model  The Eloquent model that triggered the event.
     * @param  AuditEvent  $event  The lifecycle event type.
     * @return void
     */
    public function record(Model $model, AuditEvent $event): void
    {
        try {
            if (! $this->shouldAudit($model, $event)) {
                return;
            }

            $dto = AuditEventDTO::fromModel(
                model: $model,
                event: $event,
                user: $this->userResolver->resolve(),
                except: $this->resolveExcludedAttributes($model),
                tags: $this->resolveTags($model),
            );

            if ($this->shouldQueue()) {
                $this->dispatchAsync($dto);
            } else {
                $this->writeSync($dto);
            }
        } catch (\Throwable $e) {
            // Audit failures must never break the application.
            Log::warning('[Auditor] Failed to record audit event.', [
                'event'          => $event->value,
                'auditable_type' => $model->getMorphClass(),
                'auditable_id'   => $model->getKey(),
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Writes an audit record directly to the database (synchronous path).
     *
     * Called when `auditor.queue.enabled` is false, or when the async
     * write job executes from the queue worker.
     *
     * @param  AuditEventDTO  $dto  The fully resolved audit event data.
     * @return Audit               The persisted Audit model instance.
     */
    public function writeSync(AuditEventDTO $dto): Audit
    {
        return Audit::create($dto->toArray());
    }

    /**
     * Determines whether a given model and event combination should be audited.
     *
     * Exclusion priority (highest to lowest):
     *  1. Audit model itself is always excluded (prevent infinite loops)
     *  2. Global class exclusion list from config
     *  3. Read events disabled via config
     *
     * @param  Model       $model  The model to check.
     * @param  AuditEvent  $event  The event to check.
     * @return bool
     */
    public function shouldAudit(Model $model, AuditEvent $event): bool
    {
        // Never audit the Audit model itself
        if ($model instanceof Audit) {
            return false;
        }

        // Check global exclusion list
        $excluded = config('auditor.exclude_models', []);

        foreach ($excluded as $excludedClass) {
            if ($model instanceof $excludedClass) {
                return false;
            }
        }

        // Check if read events are globally disabled
        if ($event === AuditEvent::Read && ! config('auditor.events.read', true)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves the list of attribute keys to exclude from the audit record.
     *
     * Merges the global config exclusion list with any per-model exclusions
     * declared via the Auditable interface.
     *
     * @param  Model  $model
     * @return string[]
     */
    protected function resolveExcludedAttributes(Model $model): array
    {
        $global = config('auditor.exclude_attributes', []);

        $perModel = $model instanceof Auditable
            ? $model->getAuditExcluded()
            : [];

        return array_unique(array_merge($global, $perModel));
    }

    /**
     * Resolves the tags to attach to the audit record.
     *
     * Returns per-model tags if the model implements Auditable,
     * otherwise returns an empty array.
     *
     * @param  Model  $model
     * @return string[]
     */
    protected function resolveTags(Model $model): array
    {
        return $model instanceof Auditable ? $model->getAuditTags() : [];
    }

    /**
     * Determines whether audit writes should be queued asynchronously.
     *
     * @return bool
     */
    protected function shouldQueue(): bool
    {
        return (bool) config('auditor.queue.enabled', true);
    }

    /**
     * Dispatches the audit write to a queue worker.
     *
     * Uses the queue connection and queue name from the package config.
     *
     * @param  AuditEventDTO  $dto
     * @return void
     */
    protected function dispatchAsync(AuditEventDTO $dto): void
    {
        WriteAuditJob::dispatch($dto)
            ->onConnection(config('auditor.queue.connection'))
            ->onQueue(config('auditor.queue.queue', 'audits'));
    }
}

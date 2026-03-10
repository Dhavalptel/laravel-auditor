<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Observers;

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Global Eloquent model observer.
 *
 * This observer is registered on the base Eloquent Model class in the
 * service provider, meaning it fires for EVERY model in the application
 * without any code changes to individual models.
 *
 * It maps Eloquent lifecycle hooks to AuditEvent enum values and
 * delegates all recording logic to the AuditService.
 *
 * Soft-delete vs hard-delete detection:
 * - If a model uses the SoftDeletes trait and `deleted_at` is now set,
 *   the deletion is treated as a soft delete (AuditEvent::Deleted).
 * - If the model does not use SoftDeletes, it's a hard delete.
 * - Restoring a soft-deleted model fires AuditEvent::Restored.
 */
class GlobalModelObserver
{
    /**
     * Flag to indicate an Eloquent event is currently being processed.
     * Used by the DatabaseQueryListener to skip duplicate auditing.
     */
    public static bool $processing = false;
    
    /**
     * @param  AuditService  $auditService  The core audit service.
     */
    public function __construct(
        protected readonly AuditService $auditService,
    ) {}

    /**
     * Fires after a new model has been saved to the database.
     *
     * @param  Model  $model
     * @return void
     */
    public function created(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Created);
    }

    /**
     * Fires after a model is retrieved from the database.
     *
     * This maps to the "read" event. By default, read tracking is enabled.
     * It can be disabled globally via `auditor.events.read = false`.
     *
     * Note: This fires once per model retrieval, not per query row.
     * For bulk-loaded collections, each model fires independently.
     *
     * @param  Model  $model
     * @return void
     */
    public function retrieved(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Read);
    }

    /**
     * Fires after an existing model has been updated in the database.
     *
     * Only fires when at least one attribute is dirty (changed).
     *
     * @param  Model  $model
     * @return void
     */
    public function updated(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Updated);
    }

    /**
     * Fires after a model has been deleted.
     *
     * Handles both soft deletes and hard deletes:
     * - Soft delete: model uses SoftDeletes trait and `deleted_at` is set.
     * - Hard delete: any other deletion.
     *
     * Both map to AuditEvent::Deleted, but the distinction is visible
     * in the `old_values` captured (soft-deleted record retains attributes).
     *
     * @param  Model  $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Deleted);
    }

    /**
     * Fires after a soft-deleted model has been restored.
     *
     * Only fires for models using the SoftDeletes trait.
     *
     * @param  Model  $model
     * @return void
     */
    public function restored(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Restored);
    }

    /**
     * Fires after a model is force-deleted (permanent hard delete).
     *
     * For models using SoftDeletes, `forceDeleted` is distinct from `deleted`.
     * Both map to AuditEvent::Deleted to keep the audit trail consistent.
     *
     * @param  Model  $model
     * @return void
     */
    public function forceDeleted(Model $model): void
    {
        $this->auditService->record($model, AuditEvent::Deleted);
    }
}

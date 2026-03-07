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
     * Skips updates that are internal to a restore operation — the
     * restored() hook captures those instead, preventing duplicate records.
     *
     * @param  Model  $model
     * @return void
     */
    public function updated(Model $model): void
    {
        // restore() calls save() internally, which fires `updated` before `restored`.
        // Detect this by checking if deleted_at changed to null and skip here;
        // restored() will create the authoritative audit record.
        if (array_key_exists('deleted_at', $model->getChanges()) && $model->deleted_at === null) {
            return;
        }

        $this->auditService->record($model, AuditEvent::Updated);
    }

    /**
     * Fires after a model has been deleted.
     *
     * Handles soft deletes only. Hard (force) deletes are handled by
     * forceDeleted() to avoid duplicate records — when forceDelete() is
     * called on a SoftDeletes model, Laravel fires both `deleted` and
     * `forceDeleted`, so we skip `deleted` for force-delete operations.
     *
     * @param  Model  $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        // forceDelete() fires both `deleted` and `forceDeleted` on SoftDeletes models.
        // Skip `deleted` here so forceDeleted() creates the single audit record.
        // Use isForceDeleting() to avoid Eloquent's __get resolving the static
        // SoftDeletes::forceDeleting($callback) method incorrectly.
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

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

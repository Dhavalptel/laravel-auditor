<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Traits;

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Optional trait for models that want audit relationship access and
 * fine-grained per-model configuration.
 *
 * Adding this trait is NOT required for audit tracking — every model
 * is audited automatically. This trait simply adds:
 *
 *  - `audits()` relationship for querying a model's audit history
 *  - `auditTrail()` helper for fluent history access
 *  - `recordAuditEvent()` for manually triggering audit events
 *
 * Usage:
 *
 * ```php
 * class Order extends Model implements Auditable
 * {
 *     use HasAuditOptions;
 *
 *     public function getAuditExcluded(): array
 *     {
 *         return ['internal_notes'];
 *     }
 *
 *     public function getAuditTags(): array
 *     {
 *         return ['orders', 'billing'];
 *     }
 * }
 *
 * // Then in your code:
 * $order->audits()->event('updated')->latest()->paginate(20);
 * ```
 */
trait HasAuditOptions
{
    /**
     * Returns a MorphMany relationship for all audits of this model.
     *
     * Resolves the audit model class from config to support custom model swapping.
     *
     * Enables eager loading, filtering, and counting:
     *
     * ```php
     * $user->audits()->event('updated')->count();
     * $user->audits()->latest()->paginate(50);
     * ```
     *
     * @return MorphMany<Audit>
     */
    public function audits(): MorphMany
    {
        /** @var class-string<Audit> $auditModel */
        $auditModel = config('auditor.audit_model', Audit::class);

        return $this->morphMany(
            related: $auditModel,
            name: 'auditable',
            foreignKey: 'auditable_id',
            localKey: $this->getKeyName(),
        );
    }

    /**
     * Returns a scoped query builder pre-filtered to this model's audits.
     *
     * Resolves the audit model class from config to support custom model swapping.
     * Shorthand for `Audit::forModel($this)`.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Audit>
     */
    public function auditTrail(): \Illuminate\Database\Eloquent\Builder
    {
        /** @var class-string<Audit> $auditModel */
        $auditModel = config('auditor.audit_model', Audit::class);

        return $auditModel::forModel($this);
    }

    /**
     * Manually records an audit event for this model instance.
     *
     * Useful when you need to log a custom business action that falls
     * outside the automatic Eloquent lifecycle events.
     *
     * Example:
     * ```php
     * $invoice->recordAuditEvent(AuditEvent::Updated, tags: ['payment', 'manual']);
     * ```
     *
     * @param  AuditEvent  $event  The event to record.
     * @param  string[]    $tags   Optional tags to attach.
     * @return void
     */
    public function recordAuditEvent(AuditEvent $event, array $tags = []): void
    {
        app(AuditService::class)->record($this, $event);
    }
}

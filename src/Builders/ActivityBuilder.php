<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Builders;

use DevToolbox\Auditor\DTOs\ActivityEventDTO;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent builder for manually logging activities.
 *
 * Provides a Spatie Activity Log-compatible API for recording named,
 * human-readable activities with explicit causers, subjects, and
 * arbitrary custom properties.
 *
 * Usage:
 *   Auditor::inLog('billing')
 *       ->causedBy($admin)
 *       ->performedOn($invoice)
 *       ->withProperties(['amount' => 500])
 *       ->log('Invoice created');
 *
 * All audit failures are caught silently — this builder will never throw
 * exceptions or break the host application.
 */
class ActivityBuilder
{
    /** @var string Named channel/group for this activity. */
    private string $logName = 'default';

    /** @var Model|null The model the activity was performed on. */
    private ?Model $subject = null;

    /** @var Model|null The model that caused the activity. */
    private ?Model $causer = null;

    /** @var array<string, mixed> Arbitrary custom properties. */
    private array $properties = [];

    /** @var bool Prevents ->log() from being called more than once per builder instance. */
    private bool $dispatched = false;

    /**
     * @param  AuditService  $auditService  The service used to persist the record.
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Sets the log channel name for this activity.
     *
     * @param  string  $logName  E.g. 'auth', 'billing', 'admin'.
     * @return static
     */
    public function inLog(string $logName): static
    {
        $this->logName = $logName;

        return $this;
    }

    /**
     * Sets the model that caused this activity.
     *
     * Stored in causer_type / causer_id columns. Distinct from user_type / user_id,
     * which are auto-populated from auth context by the Eloquent observer.
     *
     * @param  Model|null  $causer
     * @return static
     */
    public function causedBy(?Model $causer): static
    {
        $this->causer = $causer;

        return $this;
    }

    /**
     * Sets the model the activity was performed on.
     *
     * Stored in auditable_type / auditable_id columns.
     *
     * @param  Model|null  $subject
     * @return static
     */
    public function performedOn(?Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Replaces all custom properties with the given array.
     *
     * @param  array<string, mixed>  $properties
     * @return static
     */
    public function withProperties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    /**
     * Sets a single custom property without overwriting others.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return static
     */
    public function withProperty(string $key, mixed $value): static
    {
        $this->properties[$key] = $value;

        return $this;
    }

    /**
     * Persists the activity record with the given description.
     *
     * This is the terminal method of the builder chain. Once called, this builder
     * instance is consumed and cannot be reused.
     *
     * Returns null silently on failure or if called a second time — audit failures
     * must never break the host application.
     *
     * @param  string  $description  Human-readable description of what happened.
     * @return Audit|null
     */
    public function log(string $description): ?Audit
    {
        if ($this->dispatched) {
            logger()->warning('Auditor: ActivityBuilder::log() called more than once on the same builder instance.');
            return null;
        }

        $this->dispatched = true;

        try {
            $dto = ActivityEventDTO::fromBuilder(
                logName: $this->logName,
                description: $description,
                subject: $this->subject,
                causer: $this->causer,
                properties: $this->properties,
            );

            return $this->auditService->writeActivity($dto);
        } catch (\Throwable $e) {
            logger()->error('Auditor: Failed to write activity log — ' . $e->getMessage());

            return null;
        }
    }
}

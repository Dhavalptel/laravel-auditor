<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\DTOs;

use DevToolbox\Auditor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Data Transfer Object representing a single auditable event.
 *
 * Encapsulates all relevant metadata for one audit record, including
 * the subject model, the acting user, request context, and value diffs.
 */
final class AuditEventDTO
{
    /**
     * @param  AuditEvent       $event          The lifecycle event type.
     * @param  string           $auditableType  The morph type of the audited model (e.g. App\Models\User).
     * @param  string           $auditableId    The primary key of the audited model.
     * @param  string|null      $userType       The morph type of the acting user (nullable).
     * @param  string|int|null  $userId         The primary key of the acting user (nullable).
     * @param  array<string, mixed>  $oldValues  Attribute state before the event (empty for created/read).
     * @param  array<string, mixed>  $newValues  Attribute state after the event (empty for deleted/read).
     * @param  string|null      $ipAddress      IP address of the request (IPv4 or IPv6).
     * @param  string|null      $userAgent      User-agent string of the request client.
     * @param  string|null      $url            Full URL of the request.
     * @param  array<string>    $tags           Optional free-form tags for grouping or filtering.
     * @param  \DateTimeImmutable $occurredAt   Timestamp when the event occurred.
     */
    public function __construct(
        public readonly AuditEvent $event,
        public readonly string $auditableType,
        public readonly string $auditableId,
        public readonly ?string $userType,
        public readonly string|int|null $userId,
        public readonly array $oldValues,
        public readonly array $newValues,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?string $url,
        public readonly array $tags,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Constructs a DTO from an Eloquent model and event context.
     *
     * Automatically resolves old/new values, morph types, and request metadata.
     *
     * @param  Model           $model        The Eloquent model being audited.
     * @param  AuditEvent      $event        The event that occurred.
     * @param  Model|null      $user         The authenticated user performing the action.
     * @param  array<string>   $except       Attribute keys to exclude from value capture.
     * @param  array<string>   $tags         Optional tags to attach to the audit record.
     * @return self
     */
    public static function fromModel(
        Model $model,
        AuditEvent $event,
        ?Model $user,
        array $except = [],
        array $tags = [],
    ): self {
        [$oldValues, $newValues] = self::resolveValues($model, $event, $except);

        return new self(
            event: $event,
            auditableType: strtolower(class_basename($model->getMorphClass())),
            auditableId: (string) $model->getKey(),
            userType: $user?->getMorphClass(),
            userId: $user?->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: self::resolveIpAddress(),
            userAgent: self::resolveUserAgent(),
            url: self::resolveUrl(),
            tags: $tags,
            occurredAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Resolves old and new attribute values based on the event type.
     *
     * - Created:  no old values, new values = all current attributes
     * - Updated:  old values = original attributes, new values = changed attributes only
     * - Deleted:  old values = all current attributes, no new values
     * - Restored: old values = all current attributes, no new values
     * - Read:     no values captured
     *
     * @param  Model          $model   The Eloquent model.
     * @param  AuditEvent     $event   The lifecycle event.
     * @param  array<string>  $except  Keys to strip from the captured values.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function resolveValues(Model $model, AuditEvent $event, array $except): array
    {
        $oldValues = [];
        $newValues = [];

        $filter = static fn(array $values) => array_diff_key($values, array_flip($except));

        match ($event) {
            AuditEvent::Created => (function () use ($model, $filter, &$newValues): void {
                $newValues = $filter($model->getAttributes());
            })(),

            AuditEvent::Updated => (function () use ($model, $filter, &$oldValues, &$newValues): void {
                $dirty    = array_keys($model->getDirty());
                $original = $model->getOriginal();
                $current  = $model->getAttributes();

                foreach ($dirty as $key) {
                    $oldValues[$key] = $original[$key] ?? null;
                    $newValues[$key] = $current[$key]  ?? null;
                }

                $oldValues = $filter($oldValues);
                $newValues = $filter($newValues);
            })(),

            AuditEvent::Deleted,
            AuditEvent::Restored => (function () use ($model, $filter, &$oldValues): void {
                $oldValues = $filter($model->getAttributes());
            })(),

            AuditEvent::Read => null, // No value diff for read events
        };

        return [$oldValues, $newValues];
    }

    /**
     * Safely resolves the current request IP address.
     */
    private static function resolveIpAddress(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safely resolves the current request user-agent header.
     */
    private static function resolveUserAgent(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safely resolves the current request full URL.
     */
    private static function resolveUrl(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->fullUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Converts the DTO to a plain array suitable for database insertion.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event'          => $this->event->value,
            'auditable_type' => $this->auditableType,
            'auditable_id'   => $this->auditableId,
            'user_type'      => $this->userType,
            'user_id'        => $this->userId !== null ? (string) $this->userId : null,
            'old_values'     => $this->oldValues ?: null,
            'new_values'     => $this->newValues ?: null,
            'ip_address'     => $this->ipAddress,
            'user_agent'     => $this->userAgent,
            'url'            => $this->url,
            'tags'           => $this->tags ?: null,
            'created_at'     => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

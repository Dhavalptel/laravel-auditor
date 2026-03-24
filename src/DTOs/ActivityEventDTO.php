<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\DTOs;

use DevToolbox\Auditor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Data Transfer Object for a manually-logged activity record.
 *
 * Used exclusively by the fluent ActivityBuilder API. Unlike AuditEventDTO,
 * this DTO does not capture Eloquent attribute diffs — it carries a free-form
 * description, an optional named log channel, an explicit causer, an explicit
 * subject, and arbitrary custom properties.
 */
final class ActivityEventDTO
{
    /**
     * @param  string               $logName        Named channel/group (e.g. 'auth', 'billing').
     * @param  string               $description    Human-readable description of the activity.
     * @param  string|null          $auditableType  Morph type of the subject model (nullable).
     * @param  string|null          $auditableId    Primary key of the subject model (nullable).
     * @param  string|null          $causerType     Morph type of the explicit causer (nullable).
     * @param  string|int|null      $causerId       Primary key of the explicit causer (nullable).
     * @param  array<string, mixed> $properties     Arbitrary custom payload.
     * @param  string|null          $ipAddress      IP address of the request.
     * @param  string|null          $userAgent      User-agent string of the request client.
     * @param  string|null          $url            Full URL of the request.
     * @param  \DateTimeImmutable   $occurredAt     Timestamp when the activity was logged.
     */
    public function __construct(
        public readonly string $logName,
        public readonly string $description,
        public readonly ?string $auditableType,
        public readonly ?string $auditableId,
        public readonly ?string $causerType,
        public readonly string|int|null $causerId,
        public readonly array $properties,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?string $url,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Constructs a DTO from the accumulated state of an ActivityBuilder.
     *
     * @param  string       $logName     Named log channel.
     * @param  string       $description Human-readable activity description.
     * @param  Model|null   $subject     The model the activity was performed on.
     * @param  Model|null   $causer      The model that caused the activity.
     * @param  array<string, mixed> $properties Arbitrary custom data.
     * @return self
     */
    public static function fromBuilder(
        string $logName,
        string $description,
        ?Model $subject,
        ?Model $causer,
        array $properties,
    ): self {
        return new self(
            logName: $logName,
            description: $description,
            auditableType: $subject?->getMorphClass(),
            auditableId: $subject !== null ? (string) $subject->getKey() : null,
            causerType: $causer?->getMorphClass(),
            causerId: $causer?->getKey(),
            properties: $properties,
            ipAddress: self::resolveIpAddress(),
            userAgent: self::resolveUserAgent(),
            url: self::resolveUrl(),
            occurredAt: new \DateTimeImmutable(),
        );
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
     * Note: user_type/user_id, old_values, new_values, and tags are intentionally
     * null for the fluent activity path. The automatic Eloquent observer path
     * populates those columns via AuditEventDTO instead.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event'          => AuditEvent::Activity->value,
            'log_name'       => $this->logName,
            'description'    => $this->description,
            'auditable_type' => $this->auditableType,
            'auditable_id'   => $this->auditableId,
            'causer_type'    => $this->causerType,
            'causer_id'      => $this->causerId !== null ? (string) $this->causerId : null,
            'properties'     => $this->properties ?: null,
            'user_type'      => null,
            'user_id'        => null,
            'old_values'     => null,
            'new_values'     => null,
            'ip_address'     => $this->ipAddress,
            'user_agent'     => $this->userAgent,
            'url'            => $this->url,
            'tags'           => null,
            'created_at'     => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

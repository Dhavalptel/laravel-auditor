<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Enums;

/**
 * Represents all trackable lifecycle events on an Eloquent model.
 */
enum AuditEvent: string
{
    case Created  = 'created';
    case Read     = 'read';
    case Updated  = 'updated';
    case Deleted  = 'deleted';
    case Restored = 'restored';
    case Activity = 'activity';

    /**
     * Returns a human-readable label for the event.
     */
    public function label(): string
    {
        return match($this) {
            self::Created  => 'Created',
            self::Read     => 'Read',
            self::Updated  => 'Updated',
            self::Deleted  => 'Deleted',
            self::Restored => 'Restored',
            self::Activity => 'Activity',
        };
    }

    /**
     * Returns all event values as a plain string array.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

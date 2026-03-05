<?php

declare(strict_types=1);

use DevToolbox\Auditor\Enums\AuditEvent;

/**
 * Unit tests for the AuditEvent enum.
 */

describe('AuditEvent enum', function () {

    it('has all expected cases', function () {
        $values = AuditEvent::values();

        expect($values)->toContain('created')
            ->toContain('read')
            ->toContain('updated')
            ->toContain('deleted')
            ->toContain('restored');
    });

    it('returns a human-readable label for each case', function () {
        expect(AuditEvent::Created->label())->toBe('Created')
            ->and(AuditEvent::Read->label())->toBe('Read')
            ->and(AuditEvent::Updated->label())->toBe('Updated')
            ->and(AuditEvent::Deleted->label())->toBe('Deleted')
            ->and(AuditEvent::Restored->label())->toBe('Restored');
    });

    it('can be created from a string value', function () {
        expect(AuditEvent::from('updated'))->toBe(AuditEvent::Updated);
    });

    it('throws on an invalid value', function () {
        expect(fn () => AuditEvent::from('invalid'))->toThrow(\ValueError::class);
    });

    it('values() returns exactly 5 items', function () {
        expect(AuditEvent::values())->toHaveCount(5);
    });
});

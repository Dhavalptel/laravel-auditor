<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Contracts;

/**
 * Optional interface for models that want fine-grained audit control.
 *
 * Models implementing this interface can declare which attributes to
 * exclude from auditing and which events to skip — on a per-model basis.
 *
 * This interface is entirely opt-in. Models that do NOT implement it
 * will still be audited globally using the package configuration defaults.
 *
 * Example implementation:
 *
 * ```php
 * class User extends Model implements Auditable
 * {
 *     public function getAuditExcluded(): array
 *     {
 *         return ['password', 'remember_token', 'two_factor_secret'];
 *     }
 *
 *     public function getAuditTags(): array
 *     {
 *         return ['auth', 'users'];
 *     }
 * }
 * ```
 */
interface Auditable
{
    /**
     * Returns the list of attribute keys that should be excluded
     * from old_values and new_values in audit records.
     *
     * These are merged with the global `auditor.exclude_attributes` config.
     *
     * @return string[]
     */
    public function getAuditExcluded(): array;

    /**
     * Returns free-form tags to attach to every audit record for this model.
     *
     * Useful for grouping audits by domain (e.g. 'billing', 'auth', 'admin').
     *
     * @return string[]
     */
    public function getAuditTags(): array;
}

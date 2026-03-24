<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Scopes;

use DevToolbox\Auditor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provides convenient query scopes for the Audit model.
 *
 * These scopes enable fluent, readable queries over millions of records
 * while leveraging the composite indexes defined on the audits table.
 *
 * Example usage:
 *
 * ```php
 * // All audits for a specific model instance
 * Audit::forModel($user)->latest()->paginate(50);
 *
 * // All updates performed by an admin
 * Audit::byUser($admin)->event('updated')->get();
 *
 * // All deletes in the last 7 days
 * Audit::event('deleted')->withinDays(7)->count();
 * ```
 */
trait AuditQueryScopes
{
    /**
     * Scope to all audits for a specific model instance.
     *
     * Uses the (auditable_type, auditable_id) composite index.
     *
     * @param  Builder  $query
     * @param  Model    $model  The audited model instance.
     * @return Builder
     */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', (string) $model->getKey());
    }

    /**
     * Scope to all audits for a given morph type and optional ID.
     *
     * @param  Builder     $query
     * @param  string      $type  Fully-qualified class name or morph alias.
     * @param  mixed|null  $id    Optional model primary key.
     * @return Builder
     */
    public function scopeForType(Builder $query, string $type, mixed $id = null): Builder
    {
        $query->where('auditable_type', $type);

        if ($id !== null) {
            $query->where('auditable_id', (string) $id);
        }

        return $query;
    }

    /**
     * Scope to all audits performed by a specific user model.
     *
     * Uses the (user_type, user_id) composite index.
     *
     * @param  Builder  $query
     * @param  Model    $user  The acting user model.
     * @return Builder
     */
    public function scopeByUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', (string) $user->getKey());
    }

    /**
     * Scope to audits matching one or more event types.
     *
     * @param  Builder             $query
     * @param  AuditEvent|string   ...$events  One or more AuditEvent cases or string values.
     * @return Builder
     */
    public function scopeEvent(Builder $query, AuditEvent|string ...$events): Builder
    {
        $values = array_map(
            static fn($e) => $e instanceof AuditEvent ? $e->value : $e,
            $events,
        );

        return $query->whereIn('event', $values);
    }

    /**
     * Scope to audits created within the last N days.
     *
     * Leverages the `created_at` index for efficient time-range filtering.
     *
     * @param  Builder  $query
     * @param  int      $days  Number of days to look back.
     * @return Builder
     */
    public function scopeWithinDays(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to audits created between two timestamps.
     *
     * @param  Builder             $query
     * @param  \DateTimeInterface  $from  Start of the range (inclusive).
     * @param  \DateTimeInterface  $to    End of the range (inclusive).
     * @return Builder
     */
    public function scopeBetweenDates(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope to audits tagged with a specific tag.
     *
     * Uses MySQL JSON contains for tag array lookup.
     *
     * @param  Builder  $query
     * @param  string   $tag  The tag string to search for.
     * @return Builder
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Scope to audits where a specific attribute was changed.
     *
     * Checks for the presence of the key in new_values JSON column.
     *
     * @param  Builder  $query
     * @param  string   $attribute  The attribute name to search for.
     * @return Builder
     */
    public function scopeWhereAttributeChanged(Builder $query, string $attribute): Builder
    {
        return $query->whereNotNull("new_values->{$attribute}");
    }

    /**
     * Scope to audits in one or more named log channels.
     *
     * @param  Builder   $query
     * @param  string    ...$logNames  One or more channel names (e.g. 'auth', 'billing').
     * @return Builder
     */
    public function scopeInLog(Builder $query, string ...$logNames): Builder
    {
        return $query->whereIn('log_name', $logNames);
    }

    /**
     * Scope to audits with an exact description match.
     *
     * @param  Builder  $query
     * @param  string   $description  The activity description to search for.
     * @return Builder
     */
    public function scopeWithDescription(Builder $query, string $description): Builder
    {
        return $query->where('description', $description);
    }

    /**
     * Scope to order results from newest to oldest.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to order results from oldest to newest.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOldest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'asc');
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the audits table — the central store for all audit events.
 *
 * Indexing Strategy (designed for millions of rows):
 * ─────────────────────────────────────────────────
 *
 * 1. idx_audits_morphable  (auditable_type, auditable_id, event, created_at)
 *    → The primary workhorse. Covers "all events for this model" and
 *      "all events of type X for this model" and time-ranged variants.
 *      Query example: $user->audits()->event('updated')->latest()
 *
 * 2. idx_audits_user       (user_type, user_id, created_at)
 *    → "All actions performed by admin #42 in the last 30 days"
 *
 * 3. idx_audits_event_time (event, created_at)
 *    → "All deleted records in the past 7 days" — global event monitoring.
 *
 * 4. idx_audits_created_at (created_at)
 *    → Standalone for pruning operations: DELETE WHERE created_at < X
 *
 * Design Notes:
 * ─────────────
 * - ULID primary key: lexicographically sortable, insert-friendly (near-sequential
 *   for InnoDB), and UUID-compatible for distributed systems.
 * - `id` column is CHAR(26) — ULIDs are always 26 characters.
 * - No `updated_at` — audit records are immutable by design.
 * - `old_values` / `new_values` are JSON with MySQL partial indexing possible
 *   if specific attribute queries are needed later.
 * - ENUM for event keeps storage minimal and self-documenting.
 * - `auditable_id` is VARCHAR(36) to support both integer PKs and UUIDs.
 * - `user_id` mirrors auditable_id for the same reason.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('auditor.table', 'audits'), function (Blueprint $table) {

            // ─── Primary Key ────────────────────────────────────────────────
            // ULID: 26-char, lexicographically sortable, insert-order friendly
            $table->char('id', 26)->primary();

            // ─── Event Type ─────────────────────────────────────────────────
            $table->enum('event', ['created', 'read', 'updated', 'deleted', 'restored']);

            // ─── Audited Model (Polymorphic) ─────────────────────────────────
            // VARCHAR(36) supports both integer and UUID/ULID primary keys
            $table->string('auditable_type');
            $table->string('auditable_id', 36);

            // ─── Acting User (Polymorphic, nullable for system/guest actions)
            $table->string('user_type')->nullable();
            $table->string('user_id', 36)->nullable();

            // ─── Value Diffs ─────────────────────────────────────────────────
            // null = not applicable (e.g. old_values is null for 'created')
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // ─── Request Context ─────────────────────────────────────────────
            // IPv6 addresses can be up to 45 characters
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('url')->nullable();

            // ─── Tagging ─────────────────────────────────────────────────────
            $table->json('tags')->nullable();

            // ─── Timestamp ───────────────────────────────────────────────────
            // Single immutable timestamp — records are never updated.
            // useCurrent() sets DEFAULT CURRENT_TIMESTAMP at DB level.
            $table->timestamp('created_at')->useCurrent();

            // ─── Indexes ─────────────────────────────────────────────────────

            /**
             * Primary query pattern: "show all audit records for model X"
             * Extends to: filter by event type and/or time range on the same index.
             *
             * Covers:
             *   WHERE auditable_type = ? AND auditable_id = ?
             *   WHERE auditable_type = ? AND auditable_id = ? AND event = ?
             *   WHERE auditable_type = ? AND auditable_id = ? ORDER BY created_at DESC
             */
            $table->index(
                ['auditable_type', 'auditable_id', 'event', 'created_at'],
                'idx_audits_morphable'
            );

            /**
             * "Who did what" query pattern: all actions by a specific user.
             *
             * Covers:
             *   WHERE user_type = ? AND user_id = ?
             *   WHERE user_type = ? AND user_id = ? ORDER BY created_at DESC
             */
            $table->index(
                ['user_type', 'user_id', 'created_at'],
                'idx_audits_user'
            );

            /**
             * Global event monitoring: "show all deletions today"
             *
             * Covers:
             *   WHERE event = ? AND created_at >= ?
             */
            $table->index(
                ['event', 'created_at'],
                'idx_audits_event_time'
            );

            /**
             * Pruning index: DELETE WHERE created_at < ?
             *
             * A standalone created_at index avoids a full table scan
             * during scheduled prune operations on millions of rows.
             */
            $table->index(['created_at'], 'idx_audits_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('auditor.table', 'audits'));
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds manual activity logging columns to the audits table.
 *
 * New columns support the fluent ActivityBuilder API:
 *   - log_name   : named channel/group (e.g. 'auth', 'billing')
 *   - description: human-readable activity description
 *   - properties : arbitrary custom JSON payload from ->withProperties()
 *   - causer_type: polymorphic type for the explicit causer (from ->causedBy())
 *   - causer_id  : polymorphic ID for the explicit causer
 *
 * Also extends the event enum to include the 'activity' value
 * (MySQL/Postgres only — SQLite skips this as it does not enforce enum types).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('auditor.table', 'audits');

        Schema::table($table, function (Blueprint $table) {
            $table->string('log_name')->nullable()->after('id');
            $table->text('description')->nullable()->after('log_name');

            // Make auditable columns nullable to support manual activity records
            // where no subject model is specified via ->performedOn().
            $table->string('auditable_type')->nullable()->change();
            $table->string('auditable_id', 36)->nullable()->change();

            $table->json('properties')->nullable()->after('new_values');
            $table->string('causer_type')->nullable()->after('user_id');
            $table->string('causer_id', 36)->nullable()->after('causer_type');

            $table->index(['log_name', 'created_at'], 'idx_audits_log_name');
        });

        // Extend the event enum to include 'activity'.
        // SQLite does not enforce or support modifying enum columns — skip it there.
        // The 'activity' value will insert without error regardless.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY COLUMN `event`
                 ENUM('created','read','updated','deleted','restored','activity') NOT NULL"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('auditor.table', 'audits');

        // Restore the original enum values before dropping columns.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE `{$table}` MODIFY COLUMN `event`
                 ENUM('created','read','updated','deleted','restored') NOT NULL"
            );
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex('idx_audits_log_name');
            $table->dropColumn(['log_name', 'description', 'properties', 'causer_type', 'causer_id']);
        });
    }
};

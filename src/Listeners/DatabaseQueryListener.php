<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Listeners;

use DevToolbox\Auditor\DTOs\AuditEventDTO;
use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Jobs\WriteAuditJob;
use DevToolbox\Auditor\Observers\GlobalModelObserver;
use DevToolbox\Auditor\Resolvers\TableModelResolver;
use DevToolbox\Auditor\Resolvers\UserResolver;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listens to all executed database queries and audits raw DB::table() operations.
 *
 * This listener is registered on Laravel's QueryExecuted event in the service
 * provider. It intercepts INSERT, UPDATE, DELETE, and SELECT statements issued
 * via DB::table() (or any raw query) and records audit events for them.
 *
 * For UPDATE queries, a SELECT is executed beforehand to capture the before
 * state. This adds one extra query per audited UPDATE — acceptable for
 * correctness but worth noting in high-throughput contexts.
 *
 * Eloquent-originated queries are automatically skipped because the
 * GlobalModelObserver already handles those events.
 *
 * Flow:
 *   DB::table('archives')->update([...])
 *     → QueryExecuted event fires
 *     → parse SQL → detect UPDATE on 'archives'
 *     → run SELECT to capture old values
 *     → build AuditEventDTO
 *     → write sync or dispatch to queue
 */
class DatabaseQueryListener
{
    /**
     * Flag to prevent recursive query auditing (the SELECT we run for old_values
     * would otherwise trigger another audit event).
     */
    protected bool $isCapturingOldValues = false;

    /**
     * @param  AuditService        $auditService   Core audit write service.
     * @param  UserResolver        $userResolver   Resolves the acting user.
     * @param  TableModelResolver  $modelResolver  Maps table names to model classes.
     */
    public function __construct(
        protected readonly AuditService $auditService,
        protected readonly UserResolver $userResolver,
        protected readonly TableModelResolver $modelResolver,
    ) {}

    /**
     * Handles a QueryExecuted event.
     *
     * Parses the SQL, determines the event type, captures old values for
     * UPDATE queries, and records the audit event.
     *
     * @param  QueryExecuted  $event  The executed query event from Laravel.
     * @return void
     */
    public function handle(QueryExecuted $event): void
    {
        // Prevent recursive auditing when we SELECT for old_values
        if ($this->isCapturingOldValues) {
            return;
        }

        try {
            $sql   = trim($event->sql);
            $verb  = strtoupper(strtok($sql, ' '));

            $auditEvent = $this->resolveAuditEvent($verb);

            if ($auditEvent === null) {
                return;
            }

            $table = $this->extractTableName($sql, $verb);

            if ($table === null || $this->isExcludedTable($table)) {
                return;
            }

            $bindings  = $event->bindings;
            $oldValues = [];
            $newValues = [];

            match ($auditEvent) {
                AuditEvent::Created  => $newValues = $this->resolveInsertValues($sql, $bindings),
                AuditEvent::Updated  => [$oldValues, $newValues] = $this->resolveUpdateValues($sql, $bindings, $event->connectionName),
                AuditEvent::Deleted  => $oldValues = $this->resolveDeleteValues($sql, $bindings, $event->connectionName),
                AuditEvent::Read     => null, // No value capture for selects
                default              => null,
            };

            $auditableType = $this->modelResolver->resolve($table);
            $auditableId   = $this->extractPrimaryId($sql, $bindings) ?? '0';
            $user          = $this->userResolver->resolve();

            $dto = new AuditEventDTO(
                event: $auditEvent,
                auditableType: $auditableType,
                auditableId: $auditableId,
                userType: $user?->getMorphClass(),
                userId: $user?->getKey(),
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $this->resolveIpAddress(),
                userAgent: $this->resolveUserAgent(),
                url: $this->resolveUrl(),
                tags: ['db-query'],
                occurredAt: new \DateTimeImmutable(),
            );

            if (config('auditor.queue.enabled', true)) {
                WriteAuditJob::dispatch($dto)
                    ->onConnection(config('auditor.queue.connection'))
                    ->onQueue(config('auditor.queue.queue', 'audits'));
            } else {
                $this->auditService->writeSync($dto);
            }
        } catch (\Throwable $e) {
            Log::warning('[Auditor] Failed to audit DB query.', [
                'sql'   => $event->sql ?? null,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Event Resolution
    // -------------------------------------------------------------------------

    /**
     * Maps a SQL verb to an AuditEvent, based on the configured event list.
     *
     * Returns null if the verb is not auditable or is disabled in config.
     *
     * @param  string  $verb  Uppercase SQL verb (SELECT, INSERT, UPDATE, DELETE).
     * @return AuditEvent|null
     */
    protected function resolveAuditEvent(string $verb): ?AuditEvent
    {
        // Map SQL verbs to AuditEvent enum cases
        $verbMap = [
            'SELECT' => AuditEvent::Read,
            'INSERT' => AuditEvent::Created,
            'UPDATE' => AuditEvent::Updated,
            'DELETE' => AuditEvent::Deleted,
        ];

        $event = $verbMap[$verb] ?? null;

        if ($event === null) {
            return null;
        }

        // Use string value as key — enum cases cannot be used as array keys
        $eloquentConfigMap = [
            'read'    => 'auditor.events.read',
            'created' => 'auditor.events.created',
            'updated' => 'auditor.events.updated',
            'deleted' => 'auditor.events.deleted',
        ];

        $dbConfigMap = [
            'read'    => 'auditor.db_listener.events.read',
            'created' => 'auditor.db_listener.events.created',
            'updated' => 'auditor.db_listener.events.updated',
            'deleted' => 'auditor.db_listener.events.deleted',
        ];

        $eventValue        = $event->value; // e.g. 'updated'
        $eloquentConfigKey = $eloquentConfigMap[$eventValue] ?? null;
        $dbConfigKey       = $dbConfigMap[$eventValue] ?? null;

        $eloquentTrackingOn = $eloquentConfigKey && config($eloquentConfigKey, true);

        if ($eloquentTrackingOn) {
            // Eloquent is tracking this event — only allow if NOT fired by Eloquent
            if (GlobalModelObserver::$processing) {
                return null; // Eloquent observer will handle it
            }

            // Raw DB::table() query — check db_listener config
            if ($dbConfigKey && ! config($dbConfigKey, true)) {
                return null;
            }

            return $event;
        }

        // Eloquent tracking is OFF — fall back to db_listener config only
        if ($dbConfigKey && ! config($dbConfigKey, true)) {
            return null;
        }

        return $event;
    }

    // -------------------------------------------------------------------------
    // Table Extraction
    // -------------------------------------------------------------------------

    /**
     * Extracts the primary table name from a SQL statement.
     *
     * Handles the common patterns for each verb:
     *   INSERT INTO `table`
     *   UPDATE `table` SET
     *   DELETE FROM `table`
     *   SELECT ... FROM `table`
     *
     * @param  string  $sql   The raw SQL string.
     * @param  string  $verb  The uppercase SQL verb.
     * @return string|null    The bare table name, or null if not parseable.
     */
    protected function extractTableName(string $sql, string $verb): ?string
    {
        $pattern = match ($verb) {
            'INSERT' => '/INSERT\s+INTO\s+[`"\[]?(\w+)[`"\]]?/i',
            'UPDATE' => '/UPDATE\s+[`"\[]?(\w+)[`"\]]?/i',
            'DELETE' => '/DELETE\s+FROM\s+[`"\[]?(\w+)[`"\]]?/i',
            'SELECT' => '/FROM\s+[`"\[]?(\w+)[`"\]]?/i',
            default  => null,
        };

        if ($pattern === null) {
            return null;
        }

        if (preg_match($pattern, $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Checks whether a table should be excluded from DB query auditing.
     *
     * Always excludes the audits table itself to prevent infinite recursion.
     *
     * @param  string  $table
     * @return bool
     */
    protected function isExcludedTable(string $table): bool
    {
        $auditsTable = config('auditor.table', 'audits');

        if ($table === $auditsTable) {
            return true;
        }

        $excluded = config('auditor.db_listener.exclude_tables', []);

        return in_array($table, $excluded, true);
    }

    // -------------------------------------------------------------------------
    // Value Capture
    // -------------------------------------------------------------------------

    /**
     * Resolves new values for an INSERT statement.
     *
     * Extracts column names from the SQL and pairs them with bindings.
     *
     * @param  string         $sql       Raw INSERT SQL.
     * @param  array<mixed>   $bindings  PDO binding values.
     * @return array<string, mixed>
     */
    protected function resolveInsertValues(string $sql, array $bindings): array
    {
        // Extract column list from INSERT INTO table (col1, col2, ...) VALUES (...)
        if (preg_match('/\(([^)]+)\)\s+VALUES/i', $sql, $matches)) {
            $columns = array_map(
                fn($c) => trim($c, '`" []'),
                explode(',', $matches[1])
            );

            if (count($columns) === count($bindings)) {
                return array_combine($columns, $bindings);
            }
        }

        // Fallback: store raw bindings if column parsing fails
        return ['_bindings' => $bindings];
    }

    /**
     * Resolves old and new values for an UPDATE statement.
     *
     * Runs a SELECT query against the WHERE clause of the UPDATE to
     * capture the before state, then parses the SET clause for new values.
     *
     * @param  string         $sql             Raw UPDATE SQL.
     * @param  array<mixed>   $bindings        PDO binding values.
     * @param  string         $connectionName  DB connection name.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function resolveUpdateValues(string $sql, array $bindings, string $connectionName): array
    {
        $oldValues = [];
        $newValues = [];

        // --- Parse SET clause for new values ---
        if (preg_match('/SET\s+(.+?)\s+WHERE/is', $sql, $setMatch)) {
            $setPairs = explode(',', $setMatch[1]);
            $setBindings = array_slice($bindings, 0, count($setPairs));

            foreach ($setPairs as $i => $pair) {
                if (preg_match('/[`"\[]?(\w+)[`"\]]?\s*=\s*\?/', trim($pair), $colMatch)) {
                    $newValues[$colMatch[1]] = $setBindings[$i] ?? null;
                }
            }
        }

        // --- Capture old values via SELECT ---
        if (preg_match('/WHERE\s+(.+)$/is', $sql, $whereMatch)) {
            $whereClause  = $whereMatch[1];
            $table        = $this->extractTableName($sql, 'UPDATE');
            $whereBindings = array_slice($bindings, count($newValues));

            if ($table) {
                try {
                    $this->isCapturingOldValues = true;

                    $rows = DB::connection($connectionName)
                        ->table($table)
                        ->whereRaw($whereClause, $whereBindings)
                        ->get()
                        ->toArray();

                    if (count($rows) === 1) {
                        $oldValues = (array) $rows[0];
                    } elseif (count($rows) > 1) {
                        // Multiple rows updated — store count instead of full data
                        $oldValues = ['_affected_rows' => count($rows)];
                    }
                } catch (\Throwable) {
                    // Unable to capture old values — proceed without
                } finally {
                    $this->isCapturingOldValues = false;
                }
            }
        }

        return [$oldValues, $newValues];
    }

    /**
     * Captures old values for a DELETE statement via a SELECT before delete.
     *
     * Since the QueryExecuted event fires AFTER the query, we can only
     * capture values for DELETE if the row still exists (soft-delete tables)
     * or if this fires before commit on a transaction. For hard deletes,
     * this will typically return empty — a known limitation.
     *
     * @param  string         $sql             Raw DELETE SQL.
     * @param  array<mixed>   $bindings        PDO binding values.
     * @param  string         $connectionName  DB connection name.
     * @return array<string, mixed>
     */
    protected function resolveDeleteValues(string $sql, array $bindings, string $connectionName): array
    {
        $table = $this->extractTableName($sql, 'DELETE');

        if (! $table) {
            return [];
        }

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $whereMatch)) {
            try {
                $this->isCapturingOldValues = true;

                $rows = DB::connection($connectionName)
                    ->table($table)
                    ->whereRaw($whereMatch[1], $bindings)
                    ->get()
                    ->toArray();

                return count($rows) > 0 ? ['_rows' => $rows] : [];
            } catch (\Throwable) {
                return [];
            } finally {
                $this->isCapturingOldValues = false;
            }
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // ID Extraction
    // -------------------------------------------------------------------------

    /**
     * Attempts to extract the primary record ID from a WHERE clause.
     *
     * Looks for common patterns like `WHERE id = ?` or `WHERE \`id\` = ?`.
     * Returns null if no ID clause is found.
     *
     * @param  string        $sql       Raw SQL.
     * @param  array<mixed>  $bindings  PDO bindings.
     * @return string|null
     */
    protected function extractPrimaryId(string $sql, array $bindings): ?string
    {
        // Match: WHERE `id` = ? or WHERE id = ?
        if (preg_match('/WHERE\s+[`"\[]?id[`"\]]?\s*=\s*\?/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            // Count how many ? appear before the id binding position
            $before   = substr($sql, 0, $match[0][1]);
            $position = substr_count($before, '?');

            return isset($bindings[$position]) ? (string) $bindings[$position] : null;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Request Context
    // -------------------------------------------------------------------------

    /** @return string|null */
    protected function resolveIpAddress(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return string|null */
    protected function resolveUserAgent(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return string|null */
    protected function resolveUrl(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->fullUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master Enable Switch
    |--------------------------------------------------------------------------
    |
    | Set this to false to completely disable all auditing. No observers will
    | be registered and no audit records will be written. Useful for testing
    | environments or maintenance windows.
    |
    */
    'enabled' => env('AUDITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Audits Table Name
    |--------------------------------------------------------------------------
    |
    | The database table used to store audit records. Override this if your
    | project has table naming conventions or prefix requirements.
    |
    */
    'table' => env('AUDITOR_TABLE', 'audits'),

    /*
    |--------------------------------------------------------------------------
    | Event Tracking
    |--------------------------------------------------------------------------
    |
    | Control which Eloquent lifecycle events are tracked globally.
    | Individual models can override this via the Auditable interface.
    |
    */
    'events' => [
        'created'  => true,
        'read'     => env('AUDITOR_TRACK_READS', true),
        'updated'  => true,
        'deleted'  => true,
        'restored' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Models
    |--------------------------------------------------------------------------
    |
    | A list of fully-qualified model class names that should never be audited.
    | The Audit model itself is always excluded automatically.
    |
    | Example:
    |   'exclude_models' => [
    |       App\Models\Session::class,
    |       App\Models\Telescope\EntryModel::class,
    |   ],
    |
    */
    'exclude_models' => [
        // App\Models\Session::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Attributes
    |--------------------------------------------------------------------------
    |
    | Attribute names that should never appear in old_values or new_values.
    | These are merged with any per-model exclusions from the Auditable interface.
    |
    */
    'exclude_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled, audit writes are dispatched as queued jobs, keeping your
    | request lifecycle fast. Set connection to null to use the default.
    |
    | Recommended: use a dedicated queue worker for the 'audits' queue:
    |   php artisan queue:work --queue=audits
    |
    */
    'queue' => [
        'enabled'    => env('AUDITOR_QUEUE_ENABLED', true),
        'connection' => env('AUDITOR_QUEUE_CONNECTION', null),
        'queue'      => env('AUDITOR_QUEUE_NAME', 'audits'),
        'tries'      => 3,
        'backoff'    => [10, 30], // seconds between retry attempts
    ],

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    |
    | The class responsible for resolving the currently authenticated user.
    | Override this with your own resolver class if you have custom auth logic.
    |
    | Your custom class must extend or replace DevToolbox\Auditor\Resolvers\UserResolver
    | and implement: public function resolve(): ?\Illuminate\Database\Eloquent\Model
    |
    */
    'user_resolver' => \DevToolbox\Auditor\Resolvers\UserResolver::class,
    /*
    |--------------------------------------------------------------------------
    | Raw DB Query Listener
    |--------------------------------------------------------------------------
    |
    | The DB listener audits raw DB::table() and DB::statement() queries that
    | bypass Eloquent entirely. Since Eloquent model events (created, updated,
    | deleted, etc.) are not fired for these queries, the package hooks into
    | Laravel's QueryExecuted event and intercepts SQL statements directly.
    |
    | This is especially useful when:
    |   - You use DB::table() for bulk updates or performance-critical writes
    |   - Third-party packages write to your tables without using your models
    |   - You run raw SQL migrations or seeder scripts
    |
    | NOTE: Enabling this listener adds a small overhead to every query
    | executed in your application. For UPDATE queries specifically, an
    | additional SELECT is fired to capture the before state (old_values).
    | Consider disabling it if you only use Eloquent in your codebase.
    |
    */
    'db_listener' => [

        /*
        |----------------------------------------------------------------------
        | Enable / Disable DB Query Listener
        |----------------------------------------------------------------------
        |
        | Master switch for raw query auditing. Set to false to completely
        | disable DB::table() auditing while keeping Eloquent auditing active.
        |
        | You can also toggle this per environment:
        |   AUDITOR_DB_LISTENER_ENABLED=false  (e.g. in .env.testing)
        |
        */
        'enabled' => env('AUDITOR_DB_LISTENER_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Excluded Tables
        |----------------------------------------------------------------------
        |
        | Tables listed here will never be audited via the DB query listener.
        | The `audits` table itself is always excluded automatically to prevent
        | infinite recursion — you do not need to add it here.
        |
        | You should exclude high-frequency internal framework tables that are
        | not meaningful to audit, such as queue, cache, and session tables.
        | Add any of your own application tables that should be excluded too.
        |
        | Example — exclude a custom logging table:
        |   'exclude_tables' => [
        |       ...
        |       'activity_logs',
        |       'api_request_logs',
        |   ],
        |
        */
        'exclude_tables' => [
            'jobs',                     // Laravel queue jobs table
            'failed_jobs',              // Laravel failed queue jobs
            'cache',                    // Laravel cache store
            'sessions',                 // Laravel session store
            'telescope_entries',        // Laravel Telescope
            'telescope_entries_tags',   // Laravel Telescope
            'telescope_monitoring',     // Laravel Telescope
            'pulse_entries',            // Laravel Pulse
            'pulse_aggregates',         // Laravel Pulse
            'pulse_values',             // Laravel Pulse
            'horizon_jobs',             // Laravel Horizon
        ],

        /*
        |----------------------------------------------------------------------
        | Model Paths
        |----------------------------------------------------------------------
        |
        | The DB listener attempts to resolve a table name (e.g. 'archives')
        | to its corresponding Eloquent model class (e.g. App\Models\Archive)
        | so that audit records store a meaningful `auditable_type`.
        |
        | The app/Models directory is always scanned automatically. Add
        | additional paths here if your models live in non-standard locations,
        | such as domain folders or package directories.
        |
        | If no matching model is found for a table, the table name itself is
        | stored as the `auditable_type` (e.g. 'archives').
        |
        | Example:
        |   'model_paths' => [
        |       app_path('Domain/Billing/Models'),
        |       app_path('Domain/Users/Models'),
        |   ],
        |
        */
        'model_paths' => [],

        /*
        |----------------------------------------------------------------------
        | Audited SQL Event Types
        |----------------------------------------------------------------------
        |
        | Controls which SQL verbs are intercepted and stored as audit records.
        | These settings are independent of the top-level Eloquent `events`
        | config and only apply to raw DB::table() queries.
        |
        | created → INSERT statements  (new_values = inserted column values)
        | read    → SELECT statements  (no values captured — high volume,
        |                               disabled by default)
        | updated → UPDATE statements  (old_values captured via pre-UPDATE
        |                               SELECT, new_values = SET clause values)
        | deleted → DELETE statements  (old_values attempted post-DELETE —
        |                               may be empty for hard deletes since
        |                               the row is already gone)
        |
        | Recommendation: keep `read` disabled unless you have a specific
        | need, as every SELECT in your application will generate an audit
        | record.
        |
        */
        'events' => [
            'created' => true,
            'read'    => env('AUDITOR_DB_TRACK_READS', false),
            'updated' => true,
            'deleted' => true,
        ],

    ],
];

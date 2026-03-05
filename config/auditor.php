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

];

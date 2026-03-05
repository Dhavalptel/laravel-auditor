# Laravel Auditor — User Guide

`devtoolbox/laravel-auditor` is a **zero-touch** Laravel auditing package. It automatically tracks `created`, `read`, `updated`, `deleted`, and `restored` events on every Eloquent model in your application — without requiring any code changes to your models.

---

## Table of Contents

1. [Installation](#1-installation)
2. [Configuration](#2-configuration)
3. [How It Works](#3-how-it-works)
4. [Optional: Per-Model Configuration](#4-optional-per-model-configuration)
5. [Querying Audit Records](#5-querying-audit-records)
6. [Working with Millions of Records](#6-working-with-millions-of-records)
7. [Queue Setup](#7-queue-setup)
8. [Pruning Old Records](#8-pruning-old-records)
9. [Custom User Resolver](#9-custom-user-resolver)
10. [Disabling Auditing](#10-disabling-auditing)
11. [Facade Reference](#11-facade-reference)
12. [Database Schema Reference](#12-database-schema-reference)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Installation

### Step 1 — Install via Composer

```bash
composer require devtoolbox/laravel-auditor
```

### Step 2 — Publish the Config File

```bash
php artisan vendor:publish --provider="DevToolbox\Auditor\AuditorServiceProvider" --tag=auditor-config
```

This creates `config/auditor.php`.

### Step 3 — Run the Migration

The package loads its own migration automatically, so you can simply run:

```bash
php artisan migrate
```

This creates the `audits` table with all indexes pre-configured.

> **Optional:** If you prefer to publish the migration file to your project (e.g. to modify it):
> ```bash
> php artisan vendor:publish --provider="DevToolbox\Auditor\AuditorServiceProvider" --tag=auditor-migrations
> ```

### Step 4 — Done

That's it. Every Eloquent model in your application is now being audited automatically.

---

## 2. Configuration

`config/auditor.php`:

```php
return [

    // Master switch — set to false to disable all auditing
    'enabled' => env('AUDITOR_ENABLED', true),

    // Database table name (default: 'audits')
    'table' => env('AUDITOR_TABLE', 'audits'),

    // Which lifecycle events to track
    'events' => [
        'created'  => true,
        'read'     => env('AUDITOR_TRACK_READS', true),  // can be noisy — disable if needed
        'updated'  => true,
        'deleted'  => true,
        'restored' => true,
    ],

    // Models to never audit (fully-qualified class names)
    'exclude_models' => [
        // App\Models\Session::class,
    ],

    // Attributes to never capture in old_values / new_values
    'exclude_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    // Async queue configuration
    'queue' => [
        'enabled'    => env('AUDITOR_QUEUE_ENABLED', true),
        'connection' => env('AUDITOR_QUEUE_CONNECTION', null),  // null = default
        'queue'      => env('AUDITOR_QUEUE_NAME', 'audits'),
        'tries'      => 3,
        'backoff'    => [10, 30],  // seconds between retry attempts
    ],

    // Custom user resolver class
    'user_resolver' => \DevToolbox\Auditor\Resolvers\UserResolver::class,
];
```

### Recommended `.env` Values

```env
AUDITOR_ENABLED=true
AUDITOR_TRACK_READS=true
AUDITOR_QUEUE_ENABLED=true
AUDITOR_QUEUE_CONNECTION=redis
AUDITOR_QUEUE_NAME=audits
AUDITOR_DB_LISTENER_ENABLED=true
AUDITOR_DB_TRACK_READS=false
```

---

## 3. How It Works

The package registers a **global observer** on Laravel's base `Illuminate\Database\Eloquent\Model` class. This means every model in your application is observed automatically — no trait, no interface required.

### Event Mapping

| Eloquent Hook    | Audit Event | `old_values`              | `new_values`             |
|------------------|-------------|---------------------------|--------------------------|
| `created`        | `created`   | *(empty)*                 | All model attributes     |
| `retrieved`      | `read`      | *(empty)*                 | *(empty)*                |
| `updated`        | `updated`   | Changed attributes before | Changed attributes after |
| `deleted`        | `deleted`   | All model attributes      | *(empty)*                |
| `restored`       | `restored`  | All model attributes      | *(empty)*                |
| `forceDeleted`   | `deleted`   | All model attributes      | *(empty)*                |

> **Note on soft deletes:** Soft-delete and hard-delete both produce an `AuditEvent::Deleted` record. The distinction is visible in the `old_values` payload — a soft-deleted record retains all attributes including the `deleted_at` timestamp.

---

## 4. Optional: Per-Model Configuration

While auditing requires no changes to your models, you can optionally implement the `Auditable` interface and `HasAuditOptions` trait for fine-grained control.

```php
use DevToolbox\Auditor\Contracts\Auditable;
use DevToolbox\Auditor\Traits\HasAuditOptions;

class User extends Model implements Auditable
{
    use HasAuditOptions;

    /**
     * Attributes to exclude from audit records for this model only.
     * Merged with the global `exclude_attributes` config.
     */
    public function getAuditExcluded(): array
    {
        return ['password', 'two_factor_secret', 'api_token'];
    }

    /**
     * Tags attached to every audit record for this model.
     * Useful for grouping and filtering.
     */
    public function getAuditTags(): array
    {
        return ['users', 'auth'];
    }
}
```

### Using the `HasAuditOptions` Trait

The trait adds these conveniences to your model:

```php
// Access this model's full audit history
$user->audits()->latest()->paginate(50);

// Fluent query builder pre-filtered to this model
$user->auditTrail()->event('updated')->withinDays(30)->get();

// Manually record a custom audit event
$user->recordAuditEvent(AuditEvent::Updated, tags: ['manual', 'admin']);
```

---

## 5. Querying Audit Records

### Basic Queries

```php
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Enums\AuditEvent;

// All audits (paginated — always paginate on large tables!)
Audit::latest()->paginate(50);

// All audits for a specific model instance
Audit::forModel($user)->latest()->paginate(50);

// All audits of a specific type globally
Audit::forType(User::class)->latest()->paginate(50);

// All audits of a specific instance filtered by event
Audit::forModel($user)->event(AuditEvent::Updated)->latest()->get();

// All audits performed by a specific user
Audit::byUser($admin)->latest()->paginate(50);

// All deletions in the past 7 days
Audit::event(AuditEvent::Deleted)->withinDays(7)->latest()->get();

// Audits between two dates
Audit::betweenDates(now()->subMonth(), now())->latest()->paginate(50);

// Audits tagged with a specific tag
Audit::withTag('billing')->latest()->paginate(50);

// Audits where a specific attribute was changed
Audit::whereAttributeChanged('email')->latest()->get();
```

### Chaining Scopes

Scopes are fully chainable:

```php
// All updates to User records tagged 'auth' in the last 30 days, by admin #5
Audit::forType(User::class)
    ->event(AuditEvent::Updated)
    ->byUser($admin)
    ->withinDays(30)
    ->withTag('auth')
    ->latest()
    ->paginate(25);
```

### Inspecting a Diff

```php
$audit = Audit::find($id);

// Returns ['attribute' => ['old' => ..., 'new' => ...]]
$diff = $audit->diff();

foreach ($diff as $field => $change) {
    echo "{$field}: {$change['old']} → {$change['new']}";
}
```

### Eager Loading Relationships

```php
// Load the audited model and the user who performed the action
$audits = Audit::with(['auditable', 'user'])
    ->event(AuditEvent::Deleted)
    ->latest()
    ->paginate(25);

foreach ($audits as $audit) {
    echo $audit->user->name . ' deleted ' . class_basename($audit->auditable_type);
}
```

---

## 6. Working with Millions of Records

This section is critical for production systems. Follow these patterns to keep queries fast.

### ✅ Always Paginate — Never `->get()` on Unbounded Queries

```php
// ❌ Bad — loads everything into memory
$audits = Audit::latest()->get();

// ✅ Good — paginated
$audits = Audit::forModel($user)->latest()->paginate(50);

// ✅ Good — cursor pagination (most memory-efficient for large datasets)
$audits = Audit::forModel($user)->latest()->cursorPaginate(50);
```

### ✅ Always Filter Before Querying

Every query should use at least one indexed column as a filter. The index strategy:

| Query Pattern | Index Used |
|---|---|
| `forModel($model)` | `idx_audits_morphable` |
| `forModel($model)->event(...)` | `idx_audits_morphable` |
| `byUser($user)` | `idx_audits_user` |
| `event(...)->withinDays(...)` | `idx_audits_event_time` |
| `withinDays(...)` / `betweenDates(...)` | `idx_audits_created_at` |

### ✅ Use `cursorPaginate` for Deep Pagination

Standard `paginate()` uses `OFFSET`, which becomes slow at page 1000+. Use cursor-based pagination instead:

```php
// URL: /audits?cursor=eyJ...
$audits = Audit::forModel($user)
    ->latest()
    ->cursorPaginate(50);
```

### ✅ Use `chunk` for Large Exports or Reports

```php
// Process 10 million records without running out of memory
Audit::forModel($user)
    ->event(AuditEvent::Updated)
    ->orderBy('id') // Use indexed column for chunking
    ->chunk(1000, function ($audits) {
        foreach ($audits as $audit) {
            // process...
        }
    });
```

### ✅ Select Only Columns You Need

```php
// Don't load large JSON columns unless you need them
Audit::forModel($user)
    ->select(['id', 'event', 'user_id', 'created_at'])
    ->latest()
    ->paginate(50);
```

### ✅ Count Efficiently

```php
// ✅ Uses the index — fast even on millions of rows
$count = Audit::forModel($user)->count();

// ✅ Monthly breakdown — uses created_at index
$monthlyCounts = Audit::forModel($user)
    ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as total')
    ->groupBy('month')
    ->orderBy('month', 'desc')
    ->get();
```

### ✅ Partitioning for Very High Volume (10M+ rows)

For extremely high-volume systems, consider MySQL table partitioning by `created_at`:

```sql
ALTER TABLE audits PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION pmax  VALUES LESS THAN MAXVALUE
);
```

This allows `DROP PARTITION` instead of `DELETE` for archiving, which is near-instant.

### ✅ Archive Instead of Delete for Compliance

```php
// Move old records to an archive table rather than deleting
DB::statement('
    INSERT INTO audits_archive
    SELECT * FROM audits WHERE created_at < ?
', [now()->subYears(2)]);

DB::table('audits')->where('created_at', '<', now()->subYears(2))->delete();
```

---

## 7. Queue Setup

Audit writes are dispatched asynchronously by default. This keeps your HTTP response times fast.

### Start a Dedicated Queue Worker

```bash
# Recommended: a dedicated worker for the audits queue
php artisan queue:work --queue=audits --sleep=3 --tries=3

# With supervisor (recommended for production):
# /etc/supervisor/conf.d/audits-worker.conf
[program:audits-worker]
command=php /var/www/artisan queue:work redis --queue=audits --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
```

### Running Multiple Workers for High Volume

```bash
# For high-volume systems, run multiple workers
php artisan queue:work redis --queue=audits --sleep=1 --tries=3 &
php artisan queue:work redis --queue=audits --sleep=1 --tries=3 &
php artisan queue:work redis --queue=audits --sleep=1 --tries=3 &
```

### Disabling Queue for Testing

In your `TestCase` or `.env.testing`:

```php
// In TestCase::defineEnvironment()
$app['config']->set('auditor.queue.enabled', false);
```

```env
# .env.testing
AUDITOR_QUEUE_ENABLED=false
```

---

## 8. Pruning Old Records

Use the built-in `auditor:prune` command to keep your table size manageable.

```bash
# Prune all records older than 90 days (default)
php artisan auditor:prune

# Prune records older than 30 days
php artisan auditor:prune --days=30

# Prune only 'read' events older than 7 days
php artisan auditor:prune --days=7 --event=read

# Prune in smaller chunks (for very large tables, reduces lock time)
php artisan auditor:prune --days=90 --chunk=500

# Preview without deleting
php artisan auditor:prune --days=90 --dry-run
```

### Schedule Automated Pruning

In `routes/console.php` (Laravel 11+) or `app/Console/Kernel.php`:

```php
// routes/console.php (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('auditor:prune --days=90')->daily()->at('02:00');

// Prune read events more aggressively (they're high volume)
Schedule::command('auditor:prune --days=7 --event=read')->daily()->at('02:30');
```

---

## 9. Custom User Resolver

If your application uses a non-standard authentication system, you can provide a custom user resolver.

### Option A — Override in AppServiceProvider

```php
use DevToolbox\Auditor\Resolvers\UserResolver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserResolver::class, function () {
            return new class extends UserResolver {
                public function resolve(): ?\Illuminate\Database\Eloquent\Model
                {
                    // Your custom auth resolution
                    return MyCustomAuth::currentUser();
                }
            };
        });
    }
}
```

### Option B — Create a Dedicated Resolver Class

```php
namespace App\Audit;

use DevToolbox\Auditor\Resolvers\UserResolver;
use Illuminate\Database\Eloquent\Model;

class TenantUserResolver extends UserResolver
{
    public function resolve(): ?Model
    {
        // Resolve from a multi-tenant context
        return tenant()->currentUser();
    }
}
```

Then update `config/auditor.php`:

```php
'user_resolver' => \App\Audit\TenantUserResolver::class,
```

---

## 10. Disabling Auditing

### Globally (via .env)

```env
AUDITOR_ENABLED=false
```

### Per Model (via config exclusion)

```php
// config/auditor.php
'exclude_models' => [
    App\Models\PasswordReset::class,
    App\Models\Telescope\EntryModel::class,
    App\Models\Horizon\Job::class,
],
```

### Temporarily (within a block of code)

```php
// Suspend the global observer for one operation
\Illuminate\Database\Eloquent\Model::withoutObservers(function () {
    // These operations will not be audited
    User::where('status', 'inactive')->update(['notified' => true]);
});
```

### Per Specific Event (via config)

```php
// config/auditor.php
'events' => [
    'created'  => true,
    'read'     => false,  // Turn off read tracking globally
    'updated'  => true,
    'deleted'  => true,
    'restored' => true,
],
```

---

## 11. Facade Reference

```php
use DevToolbox\Auditor\Facades\Auditor;
use DevToolbox\Auditor\Enums\AuditEvent;

// Manually record an event
Auditor::record($model, AuditEvent::Updated);

// Check if a model/event combination would be audited
Auditor::shouldAudit($model, AuditEvent::Created); // bool

// Write directly to DB (bypasses queue)
Auditor::writeSync($dto);
```

---

## 12. Database Schema Reference

```sql
CREATE TABLE `audits` (
  `id`             CHAR(26)     NOT NULL,          -- ULID primary key
  `event`          ENUM(...)    NOT NULL,          -- created|read|updated|deleted|restored
  `auditable_type` VARCHAR(255) NOT NULL,          -- Morph class name
  `auditable_id`   VARCHAR(36)  NOT NULL,          -- Morph ID (int or UUID)
  `user_type`      VARCHAR(255) DEFAULT NULL,      -- Actor morph class (nullable)
  `user_id`        VARCHAR(36)  DEFAULT NULL,      -- Actor ID (nullable)
  `old_values`     JSON         DEFAULT NULL,      -- State before event
  `new_values`     JSON         DEFAULT NULL,      -- State after event
  `ip_address`     VARCHAR(45)  DEFAULT NULL,      -- IPv4 or IPv6
  `user_agent`     TEXT         DEFAULT NULL,      -- Request client string
  `url`            TEXT         DEFAULT NULL,      -- Full request URL
  `tags`           JSON         DEFAULT NULL,      -- Free-form string tags
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- "Show all events for User #42"
  KEY `idx_audits_morphable`  (`auditable_type`, `auditable_id`, `event`, `created_at`),

  -- "Show all actions by Admin #7"
  KEY `idx_audits_user`       (`user_type`, `user_id`, `created_at`),

  -- "Show all deletes this week"
  KEY `idx_audits_event_time` (`event`, `created_at`),

  -- Pruning: DELETE WHERE created_at < ?
  KEY `idx_audits_created_at` (`created_at`)
);
```

---

## 13. Troubleshooting

### Audits Not Being Written

1. Check `AUDITOR_ENABLED=true` in your `.env`
2. Verify the `audits` table exists: `php artisan migrate:status`
3. If using queues, verify your queue worker is running: `php artisan queue:work --queue=audits`
4. Check `auditor.exclude_models` — your model may be excluded
5. Check Laravel logs for `[Auditor]` warning messages

### Read Events Creating Too Many Records

Disable globally or for high-frequency models:

```env
AUDITOR_TRACK_READS=false
```

Or exclude specific models:
```php
'exclude_models' => [App\Models\Session::class],
```

### Queue Jobs Failing

1. Check `queue:failed` table for error details
2. Increase `auditor.queue.tries` for transient DB failures
3. Verify the queue connection (`AUDITOR_QUEUE_CONNECTION`) is correct
4. Run `php artisan queue:retry all` to retry failed audit jobs

### Queries Are Slow

1. Verify indexes exist: `SHOW INDEX FROM audits;`
2. Always filter by `auditable_type`+`auditable_id` together (they form a composite index)
3. Switch from `paginate()` to `cursorPaginate()` for deep pages
4. Select only needed columns: `->select(['id', 'event', 'created_at'])`
5. Use `EXPLAIN` on your query to confirm index usage

### Testing — Audits Not Recording in Tests

Ensure your test case disables the queue and enables auditing:

```php
protected function defineEnvironment($app): void
{
    $app['config']->set('auditor.queue.enabled', false);
    $app['config']->set('auditor.enabled', true);
    $app['config']->set('auditor.events.read', false); // optional
}
```

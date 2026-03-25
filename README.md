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
10. [Manual Activity Logging](#10-manual-activity-logging)
11. [Custom Audit Model](#11-custom-audit-model)
12. [Disabling Auditing](#12-disabling-auditing)
13. [Facade Reference](#13-facade-reference)
14. [Database Schema Reference](#14-database-schema-reference)
15. [Troubleshooting](#15-troubleshooting)

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

### The Three Auditing Layers

The package supports three distinct ways to create audit records. They serve different purposes and produce different record shapes:

| | Eloquent ORM | Raw DB (Query Listener) | Manual Fluent API |
|---|---|---|---|
| **Trigger** | Automatic | Automatic | Explicit `->log()` call |
| **Code needed** | Zero | Zero | Yes — intentional |
| **`event`** | `created` / `updated` / `deleted` / ... | `created` / `updated` / `deleted` / ... | `activity` |
| **`log_name`** | `null` | `null` | e.g. `'auth'`, `'billing'` |
| **`description`** | `null` | `null` | e.g. `'User logged in'` |
| **`old_values` / `new_values`** | Captured automatically | `null` (SQL-level) | `null` |
| **`user_type` / `user_id`** | Auto (from auth) | Auto (from auth) | `null` |
| **`causer_type` / `causer_id`** | `null` | `null` | Set via `->causedBy()` |
| **`properties`** | `null` | `null` | Set via `->withProperties()` |
| **Best for** | Model history trail | Raw query safety net | Business events, explicit actions |

#### Layer 1 — Eloquent ORM (automatic, attribute-level)

```php
// ── You write this ────────────────────────────────────────────────────────────
$user = User::create([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);

// ── Auditor automatically captures ───────────────────────────────────────────
// event          → 'created'
// auditable_type → 'App\Models\User'
// auditable_id   → 1
// user_type/id   → current authenticated user (auto-resolved from auth())
// new_values     → { "name": "Alice", "email": "alice@example.com" }
// old_values     → null
// log_name       → null  (not a manual activity)
// description    → null  (not a manual activity)
// properties     → null  (not a manual activity)
```

The Eloquent path captures the **full attribute diff** — exactly what changed, before and after. The authenticated user is resolved automatically. No code changes to your model are needed.

#### Layer 2 — Raw DB Query Builder (automatic, SQL-level)

```php
// ── You write this ────────────────────────────────────────────────────────────
DB::table('users')
    ->where('id', 1)
    ->update(['name' => 'Bob']);

// ── Auditor automatically captures ───────────────────────────────────────────
// event          → 'updated'
// auditable_type → 'App\Models\User'  (inferred from table name 'users')
// auditable_id   → null               (no model instance available at SQL level)
// user_type/id   → current authenticated user (auto-resolved)
// old_values     → null               (SQL can't see what the old value was)
// new_values     → null               (SQL can't see the full model state)
// log_name       → null
// description    → null
// properties     → null
```

The DB listener is a **safety net** for raw queries that bypass Eloquent. It knows the table, the operation type, and the current user — but it cannot produce an attribute diff because it only has access to the SQL, not the model's in-memory state.

#### Layer 3 — Manual Fluent API (intentional, business-event level)

```php
// ── You write this ────────────────────────────────────────────────────────────
Auditor::inLog('auth')
    ->causedBy($admin)
    ->performedOn($user)
    ->withProperties([
        'ip'     => request()->ip(),
        'reason' => 'forced logout by admin',
    ])
    ->log('User session terminated');

// ── Auditor captures exactly what you told it ────────────────────────────────
// event          → 'activity'
// log_name       → 'auth'
// description    → 'User session terminated'
// auditable_type → 'App\Models\User'   (from ->performedOn($user))
// auditable_id   → 1
// causer_type    → 'App\Models\Admin'  (from ->causedBy($admin))
// causer_id      → 5
// properties     → { "ip": "192.168.1.1", "reason": "forced logout by admin" }
// user_type/id   → null               (fluent path does not auto-resolve auth)
// old_values     → null               (not tracking a data change)
// new_values     → null               (not tracking a data change)
```

The fluent API records **business intent** — the *why*, not the *what*. Use it for events that aren't simple model saves: logins, policy decisions, admin actions, payment processing steps, etc.

#### Side-by-Side: Same Scenario, Three Approaches

```php
// Scenario: an admin invalidates a user's session due to suspicious activity.

// ① Eloquent — automatic, tells you WHAT data changed
$user->update(['session_token' => null]);
// → event=updated, old_values={session_token:'abc123'}, new_values={session_token:null}
// → WHO: auth()->user() auto-resolved.  WHY: unknown.

// ② DB::table — automatic SQL-level catch, but loses attribute detail
DB::table('users')->where('id', $user->id)->update(['session_token' => null]);
// → event=updated, old_values=null, new_values=null  (SQL can't diff attributes)
// → WHO: auth()->user() auto-resolved.  WHY: unknown.

// ③ Fluent API — intentional, tells you WHY it happened
Auditor::inLog('security')
    ->causedBy($admin)
    ->performedOn($user)
    ->withProperties(['reason' => 'suspicious login from unknown IP'])
    ->log('Session forcibly invalidated');
// → event=activity, log_name='security', description='Session forcibly invalidated'
// → causer=$admin, subject=$user, properties={reason:'suspicious login from unknown IP'}
// → No attribute diff — this records business intent, not a data change.
```

> **Rule of thumb:** Use Eloquent/DB automatic auditing to answer *"what changed in the data?"* and the fluent API to answer *"why did this business event happen?"*. Both run independently and complement each other — you can do all three in the same request.

#### Querying Each Layer

```php
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Enums\AuditEvent;

// All automatic audits (Eloquent + DB listener) for a specific user record
Audit::forModel($user)->latest()->paginate(25);

// Only manual activity logs in the 'security' channel
Audit::inLog('security')->event(AuditEvent::Activity)->latest()->paginate(25);

// Everything involving a user — both as auditable subject AND as explicit causer
Audit::where(function ($q) use ($user) {
    $q->forModel($user)                                       // automatic: auditable columns
      ->orWhere(function ($q) use ($user) {
          $q->where('causer_type', $user->getMorphClass())    // manual: causer columns
            ->where('causer_id', $user->getKey());
      });
})->latest()->paginate(25);
```

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

## 10. Manual Activity Logging

In addition to automatic Eloquent auditing, you can manually log activities using a fluent builder API — similar to Spatie Activity Log.

### Basic Usage

```php
use DevToolbox\Auditor\Facades\Auditor;

Auditor::inLog('auth')->log('User logged in');
```

### Log Names — Grouping Activities into Channels

Organise activities into named channels:

```php
Auditor::inLog('auth')->log('User logged in');
Auditor::inLog('billing')->log('Invoice created');
Auditor::inLog('admin')->log('Settings updated');
```

Activities without a log name default to the `'default'` channel:

```php
Auditor::newActivity()->log('Something happened'); // log_name = 'default'
```

### causedBy() — Set Who Caused the Activity

```php
Auditor::inLog('billing')
    ->causedBy($admin)
    ->log('Invoice voided');

// Stored in causer_type / causer_id columns
$audit->causer; // returns the $admin model
```

> **Note:** `causedBy()` populates `causer_type`/`causer_id`. These are distinct from `user_type`/`user_id`, which are auto-populated from the auth context by the global Eloquent observer. The two sets of columns coexist without conflict.

### performedOn() — Set the Subject Model

```php
Auditor::inLog('billing')
    ->performedOn($invoice)
    ->log('Invoice sent to customer');

// Stored in auditable_type / auditable_id columns
$audit->auditable; // returns the $invoice model
```

### withProperties() — Attach Custom Data

```php
Auditor::inLog('billing')
    ->causedBy($admin)
    ->performedOn($invoice)
    ->withProperties([
        'amount'   => 1500.00,
        'currency' => 'USD',
        'reason'   => 'Annual subscription',
    ])
    ->log('Invoice created');

// Retrieve properties
$audit->properties['amount']; // 1500.00
```

Add a single property without overwriting the rest:

```php
Auditor::withProperties(['amount' => 500])
    ->withProperty('currency', 'USD')
    ->log('Partial refund');
```

### Full Fluent Chain

```php
Auditor::inLog('billing')
    ->causedBy($admin)
    ->performedOn($invoice)
    ->withProperties(['amount' => 1500, 'plan' => 'pro'])
    ->log('Invoice created');
```

### Facade Shortcuts

Any builder method can be called directly on the facade — a new builder is created automatically:

```php
Auditor::causedBy($user)->log('Profile updated');
Auditor::performedOn($post)->log('Post viewed by admin');
Auditor::withProperties(['ip' => $request->ip()])->log('Suspicious login');
```

### Querying Manual Activity Logs

```php
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Enums\AuditEvent;

// All activities in the 'billing' channel
Audit::inLog('billing')->latest()->paginate(50);

// Multiple channels at once
Audit::inLog('billing', 'invoices')->latest()->get();

// By specific description
Audit::withDescription('Invoice created')->latest()->get();

// Filter by event type (all manual activities use AuditEvent::Activity)
Audit::event(AuditEvent::Activity)->latest()->paginate(50);

// Combine with other scopes
Audit::inLog('auth')
    ->event(AuditEvent::Activity)
    ->withinDays(7)
    ->latest()
    ->paginate(25);
```

---

## 11. Custom Audit Model

Swap the default `Audit` model for your own implementation to add custom methods, relationships, or table logic.

### Step 1 — Create Your Custom Model

```php
namespace App\Models;

use DevToolbox\Auditor\Models\Audit as BaseAudit;

class CustomAudit extends BaseAudit
{
    /**
     * Example: a custom scope for your application.
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('properties->tenant_id', $tenantId);
    }
}
```

> Your model **must extend** `DevToolbox\Auditor\Models\Audit` to preserve all built-in behaviour (ULID keys, query scopes, relationships, casts).

### Step 2 — Register the Model in Config

```php
// config/auditor.php
'audit_model' => \App\Models\CustomAudit::class,
```

That's it. All audit writes — automatic Eloquent events and manual `->log()` calls — will now create `CustomAudit` records. The `audits()` and `auditTrail()` helpers on models using `HasAuditOptions` will also resolve to your custom model automatically.

---

## 12. Disabling Auditing

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

## 13. Facade Reference

```php
use DevToolbox\Auditor\Facades\Auditor;
use DevToolbox\Auditor\Enums\AuditEvent;

// --- Automatic auditing ---

// Manually record an event
Auditor::record($model, AuditEvent::Updated);

// Check if a model/event combination would be audited
Auditor::shouldAudit($model, AuditEvent::Created); // bool

// Write directly to DB (bypasses queue)
Auditor::writeSync($dto);

// --- Manual activity logging (fluent builder) ---

// Create a fresh builder
Auditor::newActivity();                                     // ActivityBuilder

// Start the chain from any method
Auditor::inLog('auth');                                     // ActivityBuilder
Auditor::causedBy($user);                                   // ActivityBuilder
Auditor::performedOn($model);                               // ActivityBuilder
Auditor::withProperties(['key' => 'value']);                // ActivityBuilder

// Full chain — all methods return the same builder for chaining
Auditor::inLog('billing')
    ->causedBy($admin)
    ->performedOn($invoice)
    ->withProperties(['amount' => 500])
    ->withProperty('currency', 'USD')
    ->log('Invoice created');                               // ?Audit
```

---

## 14. Database Schema Reference

```sql
CREATE TABLE `audits` (
  `id`             CHAR(26)     NOT NULL,          -- ULID primary key
  `event`          ENUM(...)    NOT NULL,          -- created|read|updated|deleted|restored|activity
  `log_name`       VARCHAR(255) DEFAULT NULL,      -- Named channel (e.g. 'auth', 'billing')
  `description`    TEXT         DEFAULT NULL,      -- Human-readable activity description
  `auditable_type` VARCHAR(255) NOT NULL,          -- Morph class name
  `auditable_id`   VARCHAR(36)  NOT NULL,          -- Morph ID (int or UUID)
  `user_type`      VARCHAR(255) DEFAULT NULL,      -- Auto-resolved actor morph class (nullable)
  `user_id`        VARCHAR(36)  DEFAULT NULL,      -- Auto-resolved actor ID (nullable)
  `causer_type`    VARCHAR(255) DEFAULT NULL,      -- Explicit causer morph class (from ->causedBy())
  `causer_id`      VARCHAR(36)  DEFAULT NULL,      -- Explicit causer ID (from ->causedBy())
  `old_values`     JSON         DEFAULT NULL,      -- State before event
  `new_values`     JSON         DEFAULT NULL,      -- State after event
  `properties`     JSON         DEFAULT NULL,      -- Custom payload from ->withProperties()
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
  KEY `idx_audits_created_at` (`created_at`),

  -- "Show all billing activities"
  KEY `idx_audits_log_name`   (`log_name`, `created_at`)
);
```

---

## 15. Troubleshooting

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

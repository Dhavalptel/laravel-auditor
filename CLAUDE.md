# CLAUDE.md — Laravel Auditor

This file provides guidance for AI assistants working in this repository.

---

## Project Overview

**Package:** `devtoolbox/laravel-auditor`
**Type:** Laravel package (library, not a standalone application)
**Purpose:** Zero-touch, automatic audit logging for Eloquent models and raw DB queries in Laravel 11/12 projects.
**PHP Requirement:** ^8.2
**License:** MIT

The package intercepts Eloquent model events and raw `DB::table()` queries globally — no per-model setup required — and writes immutable audit records to a dedicated `audits` table.

---

## Repository Structure

```
laravel-auditor/
├── src/                          # All package source code (PSR-4: DevToolbox\Auditor\)
│   ├── AuditorServiceProvider.php
│   ├── Commands/
│   │   └── AuditorPruneCommand.php
│   ├── Contracts/
│   │   └── Auditable.php
│   ├── DTOs/
│   │   └── AuditEventDTO.php
│   ├── Enums/
│   │   └── AuditEvent.php
│   ├── Facades/
│   │   └── Auditor.php
│   ├── Jobs/
│   │   └── WriteAuditJob.php
│   ├── Listeners/
│   │   └── DatabaseQueryListener.php
│   ├── Models/
│   │   └── Audit.php
│   ├── Observers/
│   │   └── GlobalModelObserver.php
│   ├── Resolvers/
│   │   ├── TableModelResolver.php
│   │   └── UserResolver.php
│   ├── Scopes/
│   │   └── AuditQueryScopes.php
│   ├── Services/
│   │   └── AuditService.php
│   └── Traits/
│       └── HasAuditOptions.php
├── config/
│   └── auditor.php               # Published config file
├── database/
│   └── migrations/
│       └── 2024_01_01_000000_create_audits_table.php
├── tests/
│   ├── Feature/
│   │   ├── Commands/AuditorPruneCommandTest.php
│   │   ├── Jobs/WriteAuditJobTest.php
│   │   ├── Models/AuditModelTest.php
│   │   └── Observers/GlobalModelObserverTest.php
│   ├── Unit/
│   │   ├── AuditEventEnumTest.php
│   │   ├── DTOs/AuditEventDTOTest.php
│   │   ├── Resolvers/UserResolverTest.php
│   │   └── Services/AuditServiceTest.php
│   ├── Models.php                # Test model definitions
│   ├── Pest.php                  # Pest global helpers
│   └── TestCase.php              # Base test case
├── composer.json
└── README.md
```

---

## Core Architecture & Data Flow

### Primary Flow (Eloquent)

```
Eloquent Event (created/updated/deleted/retrieved/restored)
  → GlobalModelObserver (src/Observers/GlobalModelObserver.php)
  → AuditService::record() (src/Services/AuditService.php)
       → checks: enabled? excluded model? excluded event?
       → AuditEventDTO::fromModel() (src/DTOs/AuditEventDTO.php)
       → if queue enabled: WriteAuditJob::dispatch()
         else: AuditService::writeSync()
  → Audit record inserted into `audits` table
```

### Secondary Flow (Raw DB Queries)

```
DB::table()->insert/update/delete/select
  → DatabaseQueryListener (src/Listeners/DatabaseQueryListener.php)
       → SQL parsing
       → TableModelResolver: table name → model class
       → AuditService::record()
```

### Key Classes

| Class | Responsibility |
|-------|---------------|
| `AuditorServiceProvider` | Registers observer, listener, config, migrations, commands |
| `GlobalModelObserver` | Hooks into all Eloquent lifecycle events globally |
| `AuditService` | Orchestration: gating checks, DTO building, write routing |
| `AuditEventDTO` | Immutable value object holding all audit data |
| `WriteAuditJob` | Queued async audit writer |
| `DatabaseQueryListener` | Intercepts raw DB queries; parses SQL |
| `TableModelResolver` | Maps DB table names → PHP model class names |
| `UserResolver` | Resolves the current authenticated user across guards |
| `Audit` (Model) | The audit record; uses ULID PKs, immutable |
| `AuditQueryScopes` | Query builder scopes on the Audit model |
| `AuditorPruneCommand` | `artisan auditor:prune` with chunked deletion |

---

## Namespace & Autoloading

- **Source namespace:** `DevToolbox\Auditor\` → `src/`
- **Test namespace:** `DevToolbox\Auditor\Tests\` → `tests/`
- PSR-4 autoloading via Composer

---

## Code Conventions

### Style Requirements

- `declare(strict_types=1)` at the top of every PHP file
- PHPDoc blocks on all public methods with `@param` and `@return` tags
- Readonly properties on DTOs (immutable value objects)
- Enums for fixed domain values (e.g., `AuditEvent`)

### Error Handling Pattern

**Audit failures must never break the host application.** All audit code wraps in `try/catch`, logs the error, and silently returns. Do not throw exceptions from audit logic.

```php
try {
    // audit logic
} catch (\Throwable $e) {
    logger()->error('Auditor: ' . $e->getMessage());
}
```

### Database Conventions

- **Primary keys:** ULID (26-char, lexicographically sortable) — not auto-increment integers
- **Audit records are immutable:** no `updated_at` column; only `created_at`
- **Polymorphic relations:** `auditable_type`/`auditable_id` and `user_type`/`user_id`
- **JSON columns:** `old_values`, `new_values`, `tags`
- **IDs as VARCHAR(36)** to support both integer and UUID model PKs

### Performance Patterns

- Use chunked queries for batch operations (`->chunk(1000, ...)`)
- Use cursor pagination for large dataset iteration
- Cache table → model mappings per request (see `TableModelResolver`)
- Never load all audit records at once; always apply scopes/limits

---

## Running Tests

```bash
# Run full test suite
composer test

# Run with coverage
composer test-coverage

# Run specific file
vendor/bin/pest tests/Feature/Observers/GlobalModelObserverTest.php

# Run specific test by name
vendor/bin/pest --filter "it records a created event"
```

### Test Setup

- **Framework:** Pest 2.0 + Orchestra Testbench 9.0
- **Database:** SQLite in-memory (`:memory:`)
- **Queue:** Disabled in tests (`auditor.queue.enabled = false`)
- **Read tracking:** Disabled by default in tests (enable per-test as needed)
- Migrations run automatically via `RefreshDatabase` + `TestCase::setUp()`

### Test Models (tests/Models.php)

Three test models are pre-defined for use across tests:
- `UserModel` — implements `Auditable`, uses `HasAuditOptions`, excludes `password`
- `PostModel` — uses `SoftDeletes` for soft-delete test scenarios
- `PlainModel` — no traits, plain Eloquent model

### Writing New Tests

```php
// Feature test skeleton
it('does something', function () {
    // Arrange
    $model = UserModel::create([...]);

    // Act
    $model->update([...]);

    // Assert
    expect(Audit::count())->toBe(1);
    expect(Audit::first()->event)->toBe(AuditEvent::Updated);
});
```

---

## Configuration (config/auditor.php)

Key configuration groups:

```php
'enabled'           => env('AUDITOR_ENABLED', true),
'table'             => env('AUDITOR_TABLE', 'audits'),
'events'            => [created, read, updated, deleted, restored],
'exclude_models'    => [],          // Class names to never audit
'exclude_attributes'=> [password, remember_token, ...],
'queue'             => [enabled, connection, queue, tries, backoff],
'user_resolver'     => UserResolver::class,
'db_listener'       => [enabled, exclude_tables, model_paths, events],
```

### Relevant Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `AUDITOR_ENABLED` | `true` | Master on/off |
| `AUDITOR_TABLE` | `audits` | Audit table name |
| `AUDITOR_TRACK_READS` | `true` | Track `retrieved` events |
| `AUDITOR_QUEUE_ENABLED` | `true` | Async queue writes |
| `AUDITOR_QUEUE_CONNECTION` | `null` | Queue driver |
| `AUDITOR_QUEUE_NAME` | `audits` | Queue name |
| `AUDITOR_DB_LISTENER_ENABLED` | `true` | Intercept raw DB queries |
| `AUDITOR_DB_TRACK_READS` | `false` | Track SELECT queries |

---

## Adding New Features

### Adding a New Audit Event Type

1. Add the case to `src/Enums/AuditEvent.php`
2. Add the hook in `src/Observers/GlobalModelObserver.php`
3. Add the gate check in `src/Services/AuditService.php` if needed
4. Update `config/auditor.php` to expose the toggle
5. Write a feature test in `tests/Feature/Observers/`

### Adding a New Query Scope

1. Add method to `src/Scopes/AuditQueryScopes.php` — prefix method name with `scope`
2. The `Audit` model uses this trait, so the scope is automatically available
3. Write a unit test in `tests/Unit/` or feature test in `tests/Feature/Models/`

### Adding a New Artisan Command

1. Create command class in `src/Commands/`
2. Register in `AuditorServiceProvider::bootCommands()` (or equivalent registration method)
3. Write a feature test in `tests/Feature/Commands/`

### Modifying the Audit Schema

1. Create a **new migration** — do not modify the existing one (it may already be published to host apps)
2. Update `src/Models/Audit.php` `$fillable` and casts if needed
3. Update `src/DTOs/AuditEventDTO.php` `toArray()` method
4. Update tests

---

## Artisan Commands

```bash
# Prune old audit records
php artisan auditor:prune
php artisan auditor:prune --days=30         # Keep last 30 days
php artisan auditor:prune --event=read      # Only prune read events
php artisan auditor:prune --chunk=500       # Smaller batch size
php artisan auditor:prune --dry-run         # Preview without deleting
```

---

## Optional Per-Model Integration

Models can implement `Auditable` interface (via `HasAuditOptions` trait) for fine-grained control:

```php
use DevToolbox\Auditor\Contracts\Auditable;
use DevToolbox\Auditor\Traits\HasAuditOptions;

class User extends Model implements Auditable
{
    use HasAuditOptions;

    public function getAuditExcluded(): array
    {
        return ['password', 'remember_token'];
    }

    public function getAuditTags(): array
    {
        return ['users'];
    }
}
```

The `HasAuditOptions` trait also adds:
- `audits()` — `MorphMany` relationship to `Audit`
- `auditTrail()` — scoped query helper
- `recordAuditEvent(AuditEvent $event)` — manual event recording

---

## Querying Audits

```php
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Enums\AuditEvent;

// All audits for a model instance
Audit::forModel($user)->latest()->get();

// By event type
Audit::event(AuditEvent::Updated)->get();
Audit::event(AuditEvent::Created, AuditEvent::Updated)->get();

// By user
Audit::byUser($admin)->get();

// Date ranges
Audit::withinDays(7)->get();
Audit::betweenDates($start, $end)->get();

// By tag
Audit::withTag('users')->get();

// Attribute-level filtering
Audit::whereAttributeChanged('email')->get();
```

---

## Service Provider Registration

The package auto-discovers via `composer.json` extra section. In host apps, the provider registers:

1. Config: publishes `config/auditor.php`
2. Migrations: publishes migration to host app
3. `GlobalModelObserver` on base `Model` class (event dispatcher wildcard)
4. `DatabaseQueryListener` on `QueryExecuted` event
5. `AuditorPruneCommand` for `artisan auditor:prune`
6. Binds `AuditService` and `UserResolver` to the container

---

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `illuminate/database` | ^11.0\|^12.0 | Eloquent ORM |
| `illuminate/events` | ^11.0\|^12.0 | Event dispatcher |
| `illuminate/queue` | ^11.0\|^12.0 | Queue jobs |
| `illuminate/support` | ^11.0\|^12.0 | Helpers, service provider |
| `orchestra/testbench` | ^9.0 (dev) | Laravel package testing |
| `pestphp/pest` | ^2.0 (dev) | Test framework |
| `pestphp/pest-plugin-laravel` | ^2.0 (dev) | Pest + Laravel integration |

---

## Important Constraints for AI Assistants

1. **Never break the audit-failure-silent rule.** All audit logic must catch exceptions and log them rather than rethrowing.
2. **Do not add `updated_at` to the audits table** — audit records are intentionally immutable.
3. **Do not change ULID PKs to auto-increment** — ULIDs are by design for sortability and distributed systems.
4. **Always write tests** for new features. Use Pest syntax, not PHPUnit.
5. **Strict types required** — add `declare(strict_types=1)` to every new PHP file.
6. **Avoid breaking host apps** — this is a library. Be conservative with changes to `AuditService`, `GlobalModelObserver`, and the migration.
7. **Schema changes require a new migration** — never modify the existing migration file.
8. **Run tests before pushing:** `composer test` must pass with no failures.

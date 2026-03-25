---
name: debugger
description: >
  Laravel debugging skill. Use this whenever a user has an error, exception,
  unexpected behavior, or something not working in their Laravel application.
  Triggers on: pasted stack traces, HTTP error codes (404, 419, 500), "getting
  an error", "this is broken", "not working", "why is this happening", "help me
  debug", "job not firing", "queue issue", "migration failing", or any time a
  user describes unexpected Laravel behavior. Always diagnose root cause before
  suggesting fixes — never lead with "clear your cache".
---

# Laravel Debugger

Identify the root cause precisely. Explain why it happens. Provide a concrete fix.
Never guess — if you need more information, ask for the specific thing missing.

## Step 1 — Triage the Error Type

Read the error message and stack trace carefully. Classify it:

### HTTP Errors
| Code | Common Causes |
|------|--------------|
| 404  | Route not defined, wrong HTTP verb, route cache stale, `RouteServiceProvider` prefix issue |
| 405  | HTTP method mismatch (GET vs POST on the route) |
| 419  | CSRF token missing, expired session, `VerifyCsrfToken` not excluding API route |
| 422  | Validation failed — check `$errors` or response JSON `errors` key |
| 500  | Unhandled exception — **always check `storage/logs/laravel.log`** |

### Eloquent / Database
| Error | Diagnosis |
|-------|-----------|
| `QueryException` | Parse the SQL in the message; check bindings, column names, table names |
| `MassAssignmentException` | Column not in `$fillable`; or `$guarded = ['*']` effectively |
| `ModelNotFoundException` | `findOrFail()` — check the ID being passed; check soft deletes |
| `SQLSTATE[23000]` | Unique constraint violation — check for duplicate before insert |
| `General error: 1364` | NOT NULL column has no default and isn't being set |

### Authentication / Authorization
| Error | Diagnosis |
|-------|-----------|
| `AuthenticationException` (401) | `auth` middleware missing; wrong guard in `config/auth.php` |
| `AuthorizationException` (403) | Policy returning `false`; `authorize()` failing — debug the policy directly |
| Redirect loop on login | `redirectTo()` pointing at a guarded route |

### Queue / Jobs
| Symptom | Diagnosis |
|---------|-----------|
| Job never runs | `QUEUE_CONNECTION=sync` only runs in same request; check `.env` |
| Job fails silently | Check `failed_jobs` table; run `php artisan queue:failed` |
| `ModelNotFoundException` in job | Model deleted between dispatch and execution; check `SerializesModels` + soft deletes |
| Job runs but does nothing | Exception swallowed — add `$this->fail()` or check `tries` limit |

### Service Container
| Error | Diagnosis |
|-------|-----------|
| `BindingResolutionException` | Interface not bound in a service provider; typo in class name |
| `Target [X] is not instantiable` | Resolving an interface/abstract without a binding |
| Circular dependency | Constructor A injects B, B injects A — use `$app->make()` lazily or restructure |

### Config / Environment
| Symptom | Diagnosis |
|---------|-----------|
| Config change not taking effect | `config:cache` stale — run `php artisan config:clear` |
| `.env` value not loading | Syntax error in `.env` (spaces around `=`, missing quotes on values with `#`) |
| `APP_KEY` error | Run `php artisan key:generate` |

---

## Step 2 — Diagnostic Commands

Suggest the precise commands needed, not a blanket cache-clear:

```bash
# Read the actual error (always start here for 500s)
tail -n 100 storage/logs/laravel.log

# Cache issues only
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Route debugging
php artisan route:list --path=api/orders
php artisan route:list --name=orders.store

# Queue debugging
php artisan queue:failed
php artisan queue:retry all
php artisan queue:work --once   # run one job manually to watch output

# Live query debugging (add to controller temporarily)
\DB::listen(fn($q) => logger($q->sql, $q->bindings));

# Tinker for interactive testing
php artisan tinker
```

---

## Step 3 — Ask for Missing Context

If you cannot determine the root cause, ask for exactly what you need — not everything:

- "Can you share the full stack trace from `storage/logs/laravel.log`?"
- "Can you show the model's `$fillable` and `$casts` properties?"
- "What does `php artisan route:list --name=X` output?"
- "Is this running with `QUEUE_CONNECTION=sync` in your `.env`?"

---

## Output Format

```
## Root Cause
One clear paragraph: exactly what is failing and why.

## Why This Happens
The Laravel/PHP mechanism behind the error (brief — 2-4 sentences).

## Fix
Corrected code or config, with an explanation of each change made.

## Verify
How to confirm the fix worked — specific command, test, or expected output.

## Prevention *(if valuable)*
What to do differently to avoid this class of bug going forward.
```

## Rules
- State root cause **before** the fix — understanding matters
- If multiple causes are possible, list them by likelihood and how to test each
- Never suggest disabling error handling or using `@` suppressor
- Never suggest "try clearing cache" without a reason to believe caching is involved
- If the error is in vendor code, trace back to the user's code that triggered it

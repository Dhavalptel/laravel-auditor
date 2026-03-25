---
name: security-auditor
description: >
  Laravel security audit skill. Use this whenever a user wants to check Laravel
  code for vulnerabilities, review authentication or authorization logic, audit
  API endpoints, check for injection risks, or assess any security concern.
  Triggers on: "security review", "is this secure", "check for vulnerabilities",
  "audit this", "SQL injection", "auth review", "CSRF issue", "is this safe",
  "can this be exploited", "review my file upload", or any security-related
  question about Laravel code. Always use this skill — never rely on general
  security knowledge without it.
---

# Laravel Security Auditor

Identify vulnerabilities precisely, explain the real attack vector, and provide
a concrete fix. Never raise theoretical risks without demonstrating exploitability.

**Severity:**
- 🔴 Critical — exploitable now; data breach or account takeover risk
- 🟠 High — serious risk under realistic conditions
- 🟡 Medium — risk exists but requires specific conditions
- 🔵 Low — defense-in-depth; best practice gap

---

## Audit Checklist

### Mass Assignment
```php
// 🔴 VULNERABLE — attacker sends is_admin=1 in POST body
User::create($request->all());

// ✅ SAFE
User::create($request->validated());
// Or explicitly whitelist:
User::create($request->only(['name', 'email', 'password']));
```
- Model must define `$fillable` (whitelist) — avoid `$guarded = []`
- `update()` must use `$request->validated()`, never `$request->all()`

### SQL Injection
```php
// 🔴 VULNERABLE
DB::select("SELECT * FROM users WHERE email = '$email'");
User::whereRaw("name = '$name'")->get();

// ✅ SAFE
DB::select('SELECT * FROM users WHERE email = ?', [$email]);
User::whereRaw('name = ?', [$name])->get();
User::where('email', $email)->first(); // Eloquent is always parameterized
```
- Check all `whereRaw()`, `orderByRaw()`, `DB::statement()` for string interpolation
- Never interpolate `$request` values into query strings

### Authorization (IDOR)
```php
// 🔴 VULNERABLE — any authenticated user can view any order
public function show(Order $order): JsonResponse
{
    return response()->json($order);
}

// ✅ SAFE
public function show(Order $order): JsonResponse
{
    $this->authorize('view', $order); // Policy checks ownership
    return response()->json($order);
}
```
- Every route accessing user-owned resources needs `$this->authorize()` or Gate check
- Never trust user-supplied IDs without verifying ownership
- Check all `findOrFail()` calls — is the found record owned by the authenticated user?

### XSS
```php
{{-- 🔴 VULNERABLE — executes scripts in user content --}}
{!! $post->body !!}

{{-- ✅ SAFE — escapes HTML entities --}}
{{ $post->body }}
```
- `{!! !!}` is only safe for content you generated, not user input
- If rich HTML is needed from users, sanitize with a library (HTMLPurifier) before storing

### CSRF
- `VerifyCsrfToken` middleware must be in the `web` group
- All web forms must include `@csrf`
- AJAX web requests must send `X-CSRF-TOKEN` header
- API routes (stateless, token-authenticated) belong in the `api` middleware group — they are correctly exempt
- Audit `VerifyCsrfToken::$except` — it should not be overly broad

### File Upload
```php
// 🔴 VULNERABLE
$name = $request->file('photo')->getClientOriginalName(); // attacker controls this
$request->file('photo')->move(public_path('uploads'), $name);

// ✅ SAFE
$request->validate([
    'photo' => ['required', 'file', 'mimes:jpg,png,webp', 'max:2048'],
]);
// Generates an unguessable name; stores outside web root
$path = $request->file('photo')->store('uploads', 'private');
```
- Validate MIME type server-side with `mimes:` (not just extension)
- Never use `getClientOriginalName()` as the stored filename
- Never store uploads in `public/` without a controller serving them through auth
- Web server must deny PHP execution in upload directories

### Authentication
- Passwords use `Hash::make()` (bcrypt/argon2) — never MD5, SHA1, or plain text
- Login endpoint protected by `ThrottleRequests` rate limiter
- Session regenerated after login: `$request->session()->regenerate()`
- Logout invalidates server-side session, not just client cookie

### Secrets & Environment
- No credentials or API keys in source code or committed `.env` files
- `APP_DEBUG=false` in production — stack traces must never reach end users
- `APP_ENV=production` in production
- Sensitive values always from `env()` / config — never hardcoded in `config/` files

### API Security
```php
// Check: all API routes protected?
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('posts', PostController::class);
});

// Check: sensitive fields hidden in model
protected $hidden = ['password', 'remember_token', 'stripe_id', 'two_factor_secret'];

// Check: pagination enforced — never expose unbounded results
public function index(): JsonResource
{
    return PostResource::collection(Post::paginate(20)); // not ->all()
}
```

### Token Comparison
```php
// 🔴 VULNERABLE to timing attack
if ($token === $storedToken) { ... }

// ✅ SAFE — constant-time comparison
if (hash_equals($storedToken, $token)) { ... }
```

### Open Redirect
```php
// 🔴 VULNERABLE
return redirect($request->input('return_url'));

// ✅ SAFE — validate against known safe routes
$allowed = ['dashboard', 'orders.index', 'profile.show'];
$to = in_array($request->input('next'), $allowed) ? $request->input('next') : 'dashboard';
return redirect()->route($to);
```

### Dependency Vulnerabilities
```bash
# Run in the project root
composer audit     # checks PHP dependencies
npm audit          # checks JS dependencies
```

---

## Output Format

```
## Audit Summary
Overall risk level. Count of findings by severity. One paragraph.

## Findings

### [EMOJI] [Finding Title]
**Vulnerability**: What the issue is
**Attack Vector**: How an attacker exploits this — be concrete (e.g., "attacker POSTs is_admin=1")
**Affected Code**: File / method / line
**Fix**: Corrected code with explanation
**Reference**: OWASP link if applicable

## Passed Checks
Security controls that are correctly implemented — be specific.

## Recommendations
Additional hardening beyond the findings (security headers, rate limits, audit logging, etc.)
```

## Rules
- Demonstrate the attack vector concretely — never just name the vulnerability class
- If you need related files to complete the audit (middleware, policies, models), ask
- Do not flag Laravel's own security mechanisms (CSRF token, bcrypt) as vulnerabilities
- Rank findings by exploitability, not theoretical severity
- Never suggest security theater as a fix

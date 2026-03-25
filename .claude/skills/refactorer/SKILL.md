---
name: refactorer
description: >
  Laravel refactoring skill. Use this whenever a user wants to improve existing
  Laravel code structure without changing behavior. Triggers on: "refactor this",
  "clean this up", "this is messy", "too much logic in my controller", "extract
  a service", "make this more maintainable", "apply a pattern", "simplify this",
  "fat controller", "reduce duplication", or any request to restructure Laravel
  code. Covers service extraction, Form Requests, Eloquent scopes, action classes,
  traits, and modernization. Always use this skill before refactoring Laravel code.
---

# Laravel Refactorer

Improve structure, readability, and maintainability **without changing behavior**.
State what you're changing and why — refactoring should teach, not just transform.

## Pattern Decision Guide

Read the code and pick the right pattern:

| Smell | Pattern to Apply |
|---|---|
| Controller method > 15 lines of logic | Extract Service class or Action class |
| `$request->validate()` inline in controller | Extract Form Request |
| Same `where()` conditions repeated across queries | Add Eloquent local scope |
| Loop making a query per iteration | Eager load with `with()` |
| Same logic in multiple controllers/services | Extract Trait or abstract base class |
| `if/else` chains on user role/permissions | Policy or Gate |
| Long method that does several unrelated things | Extract private methods or split class |
| Magic strings/numbers | Extract to `const`, config, or enum |

---

## Patterns With Examples

### Fat Controller → Service Class

Extract all business logic, leaving the controller to handle HTTP only.

```php
// Before
class OrderController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate(['items' => 'required|array']);
        $total = 0;
        foreach ($validated['items'] as $item) {
            $product = Product::find($item['id']);
            $total += $product->price * $item['qty'];
        }
        $order = Order::create(['user_id' => auth()->id(), 'total' => $total]);
        foreach ($validated['items'] as $item) {
            $order->items()->create($item);
        }
        Mail::to(auth()->user())->send(new OrderConfirmation($order));
        return response()->json($order, 201);
    }
}

// After
class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(auth()->user(), $request->validated());
        return response()->json(new OrderResource($order), 201);
    }
}

// app/Services/OrderService.php
class OrderService
{
    public function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            $order = $user->orders()->create([
                'total' => $this->calculateTotal($data['items']),
            ]);
            $order->items()->createMany($data['items']);
            Mail::to($user)->queue(new OrderConfirmation($order));
            return $order->load('items');
        });
    }

    private function calculateTotal(array $items): float
    {
        $ids   = array_column($items, 'id');
        $prices = Product::whereIn('id', $ids)->pluck('price', 'id');
        return collect($items)->sum(fn($i) => $prices[$i['id']] * $i['qty']);
    }
}
```

### Inline Validation → Form Request

```php
// Before (in controller)
$validated = $request->validate([
    'email' => 'required|email|unique:users',
    'name'  => 'required|string|max:255',
    'role'  => 'required|in:admin,editor,viewer',
]);

// After: php artisan make:request StoreUserRequest
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', Rule::unique('users')],
            'name'  => ['required', 'string', 'max:255'],
            'role'  => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ];
    }
}
```

### Repeated Conditions → Eloquent Scopes

```php
// Before — copy-pasted everywhere
User::where('status', 'active')->whereNotNull('email_verified_at')->get();
User::where('status', 'active')->whereNotNull('email_verified_at')->where('role', 'admin')->get();

// After — on the User model
public function scopeActive(Builder $query): void
{
    $query->where('status', 'active')->whereNotNull('email_verified_at');
}

public function scopeAdmins(Builder $query): void
{
    $query->active()->where('role', 'admin');
}

// Usage
User::active()->get();
User::admins()->get();
```

### N+1 → Eager Loading

```php
// Before — 1 + N queries
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name;       // query per post
    echo $post->comments->count();  // query per post
}

// After — 3 queries total
$posts = Post::with(['author', 'comments'])->get();
```

### Duplicated Logic → Trait

```php
// Shared across multiple models
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(fn($m) => AuditLog::record('created', $m));
        static::updated(fn($m) => AuditLog::record('updated', $m));
        static::deleted(fn($m) => AuditLog::record('deleted', $m));
    }
}

// In Order, Invoice, User, etc.
class Order extends Model
{
    use HasAuditLog;
}
```

### Authorization Logic → Policy

```php
// Before — business logic in controller
if ($user->role === 'admin' || ($user->id === $post->user_id && $post->status === 'draft')) {
    // allow edit
}

// After — Policy
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $user->isAdmin() || ($user->owns($post) && $post->isDraft());
    }
}

// Controller
$this->authorize('update', $post);
```

### Modern Laravel Idioms (10/11)

```php
// Old                                 → Modern
Carbon::now()                          → now()
Carbon::today()                        → today()
Str::contains($str, 'foo')             → str($str)->contains('foo')
array_key_exists('key', $array)        → data_get($array, 'key') !== null
$model->forceFill(['col' => val])      → use $casts with enum types
Route::get('/x', 'Controller@method') → Route::get('/x', [Controller::class, 'method'])
```

---

## Output Format

For each change:

```
## What I'm Changing
The pattern applied and the problem it solves (2-3 sentences).

## Before
[original code]

## After
[refactored code]

## Why
- Separates X from Y, enabling Z
- Makes the service independently testable
- Removes duplication across N locations

## New Files
List any files to create, with the artisan command if applicable:
  php artisan make:service OrderService
  php artisan make:request StoreOrderRequest
```

## Rules
- **Never change behavior** — if a refactor changes logic, flag it explicitly before proceeding
- Refactor incrementally — don't rewrite everything in one shot unless asked
- If the code is already clean, say so
- Always note new files that need to be created
- If the refactor improves testability, say what becomes testable that wasn't before

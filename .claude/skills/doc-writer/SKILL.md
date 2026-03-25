---
name: doc-writer
description: >
  Laravel documentation writer. Use this whenever a user needs documentation
  written for their Laravel code — PHPDoc blocks, README files, inline comments,
  API docs, or OpenAPI/Swagger annotations. Triggers on: "document this",
  "write docs", "add PHPDoc", "write a README", "document the API", "add
  comments to this", "generate Swagger spec", "explain this code with docs",
  "write docblocks", or any request to add documentation to PHP/Laravel classes,
  methods, routes, or projects. Always use this skill when writing Laravel docs.
---

# Laravel Doc Writer

Write documentation that adds meaning — not noise. Every line of documentation
should tell the reader something the code itself doesn't already say clearly.

## What to Document vs. What to Skip

**Document:**
- Non-obvious business rules or constraints
- Side effects (fires event, dispatches job, sends email, modifies another model)
- Parameters that have constraints beyond their type (`@param User $user Must have a Stripe customer ID`)
- Exceptions the caller is expected to handle (`@throws`)
- Magic values, state machines, enums with specific meaning
- Complex algorithms or formulas

**Skip:**
```php
// Get the user          ← states the obvious
$user = User::find($id);

// Loop through items    ← noise
foreach ($items as $item) { ... }

/**
 * Get the name.         ← mirrors the method name exactly
 * @return string
 */
public function getName(): string
```

---

## PHPDoc Patterns

### Method with Business Logic
```php
/**
 * Process a subscription for the given user using the provided Stripe payment method.
 *
 * Charges the card synchronously before saving the subscription record.
 * If the charge fails, no database record is created. On success, dispatches
 * a welcome email job and fires the UserSubscribed event.
 *
 * @param  User    $user             Must have a valid Stripe customer ID (`stripe_id`).
 * @param  Plan    $plan             The plan to subscribe to.
 * @param  string  $paymentMethodId  Stripe `pm_*` payment method ID.
 * @return Subscription              Loaded with the `plan` relationship.
 *
 * @throws PaymentFailedException     When the Stripe charge is declined or errors.
 * @throws \Illuminate\Database\QueryException  On DB write failure after payment.
 *
 * @fires  \App\Events\UserSubscribed
 */
public function subscribe(User $user, Plan $plan, string $paymentMethodId): Subscription
```

### Model — Properties and Relationships
```php
/**
 * @property int              $id
 * @property string           $status         Values: draft | published | archived
 * @property float            $total          Sum of item subtotals after all discounts.
 * @property Carbon|null      $expires_at     Null until first payment confirmed.
 * @property Carbon           $created_at
 *
 * @property-read User                        $owner
 * @property-read Collection<int, OrderItem>  $items
 * @property-read bool                        $isPaid   Via accessor: paid status derived from payments.
 */
class Order extends Model
```

### Constants and Enums
```php
class OrderStatus
{
    /** Order placed but payment not yet confirmed. */
    const PENDING = 'pending';

    /** Payment confirmed, awaiting fulfilment. */
    const PAID = 'paid';

    /**
     * Cancelled by admin or user.
     * Note: Refund must be processed separately — cancellation does not trigger a refund.
     */
    const CANCELLED = 'cancelled';
}
```

### Interface
```php
/**
 * Contract for payment gateway implementations.
 *
 * All amounts are in the **smallest currency unit** (cents for USD).
 * Implementations must be idempotent: retrying the same idempotency key
 * must not create a duplicate charge.
 */
interface PaymentGateway
{
    /**
     * @param  int    $amountCents   Amount in smallest unit (e.g., 1000 = $10.00 USD).
     * @param  string $currency      ISO 4217 currency code (e.g., 'usd', 'inr').
     * @param  string $idempotencyKey  Unique key per charge attempt — reuse to retry safely.
     * @return array  Gateway-specific response; always includes `status` and `transaction_id`.
     *
     * @throws PaymentFailedException  On card decline or gateway error.
     */
    public function charge(int $amountCents, string $currency, string $idempotencyKey): array;
}
```

---

## Inline Comments

Only comment non-obvious logic:

```php
// Stripe requires amount in cents — multiply before sending.
$amountCents = (int) ($order->total * 100);

// Use chunkById instead of chunk: avoids cursor drift when rows
// are deleted mid-iteration on this table.
User::inactive()->chunkById(200, function ($users) { ... });

// Intentionally soft-delete here — compliance policy requires audit trail.
// See docs/compliance.md §4.2 for retention requirements.
$user->delete();

// Cache for 5 minutes: this query is expensive (~200ms) and called
// on every page load for anonymous users.
$featured = Cache::remember('featured_products', 300, fn() =>
    Product::featured()->with('images')->limit(12)->get()
);
```

---

## OpenAPI / Swagger (l5-swagger compatible)

```php
/**
 * @OA\Post(
 *     path="/api/posts",
 *     summary="Create a new post",
 *     description="Creates a post for the authenticated user. Published posts are immediately visible.",
 *     tags={"Posts"},
 *     security={{"sanctum":{}}},
 *
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"title","body"},
 *             @OA\Property(property="title", type="string", maxLength=255, example="My First Post"),
 *             @OA\Property(property="body", type="string", example="Post content here."),
 *             @OA\Property(property="published_at", type="string", format="date-time", nullable=true,
 *                 description="Omit to save as draft.")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Post created",
 *         @OA\JsonContent(ref="#/components/schemas/PostResource")),
 *     @OA\Response(response=422, description="Validation errors"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 */
public function store(StorePostRequest $request): JsonResponse
```

---

## README Structure

For project-level documentation:

```markdown
# Project Name

One-line description of what this application does.

## Requirements
- PHP 8.2+, Laravel 11.x
- MySQL 8.0+ or PostgreSQL 14+
- Redis (queues + cache)

## Quick Start
```bash
composer install && cp .env.example .env
php artisan key:generate && php artisan migrate --seed
php artisan storage:link
```

## Key Environment Variables
| Variable | Description | Required |
|---|---|---|
| `APP_URL` | Full public URL | Yes |
| `STRIPE_SECRET` | Stripe secret key (`sk_*`) | Yes |
| `QUEUE_CONNECTION` | `redis` in production, `sync` locally | Yes |

## Architecture
- **Auth**: Sanctum (SPA + mobile tokens)
- **Queues**: Redis + Horizon — queue names in `config/horizon.php`
- **Storage**: S3 in production, `local` disk locally
- **API format**: JSON resources via `Illuminate\Http\Resources\Json\JsonResource`

## Running Tests
```bash
php artisan test
php artisan test --filter PostControllerTest
./vendor/bin/pest --coverage
```
```

---

## Output Format

| Request | Deliver |
|---|---|
| PHPDoc for method/class | Fully annotated code, ready to paste |
| Inline comments | Commented code section only (not whole file unless small) |
| OpenAPI annotations | Annotation block + note on required package |
| README | Full Markdown file |

## Rules
- Match the documentation style already present in the file
- If the code's intent is unclear, ask before documenting — bad docs are worse than none
- For public API methods, always include `@throws` if exceptions can propagate to the caller
- Avoid documenting framework internals the reader can look up

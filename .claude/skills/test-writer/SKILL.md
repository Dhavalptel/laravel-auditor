---
name: test-writer
description: >
  Laravel test writing skill using Pest PHP or PHPUnit. Use this whenever a user
  wants to write tests for any Laravel code — controllers, API endpoints, services,
  jobs, events, models, middleware, or policies. Triggers on: "write tests for
  this", "add test coverage", "how do I test this", "feature test", "unit test",
  "test this endpoint", "write a Pest test", or any request to create test files
  for Laravel. Defaults to Pest PHP unless the user specifies PHPUnit. Always
  use this skill when writing Laravel tests — it contains patterns for auth,
  mocking, factories, datasets, and database assertions.
---

# Laravel Test Writer

Write clean, meaningful tests that document behavior. Default to **Pest PHP** unless
the user specifies PHPUnit.

## Test Type Decision

| What to test | Test type | Location |
|---|---|---|
| HTTP endpoint → response + DB state | Feature | `tests/Feature/` |
| Auth, authorization, policies | Feature | `tests/Feature/` |
| Job dispatching via HTTP | Feature | `tests/Feature/` |
| Mail/notification sent via action | Feature | `tests/Feature/` |
| Service class pure logic | Unit | `tests/Unit/` |
| Model scopes/accessors (no DB) | Unit | `tests/Unit/` |
| Value objects, helpers, formatters | Unit | `tests/Unit/` |

**File naming**: Mirror the class under test.
- `app/Http/Controllers/Api/PostController.php` → `tests/Feature/Api/PostControllerTest.php`
- `app/Services/InvoiceService.php` → `tests/Unit/Services/InvoiceServiceTest.php`

---

## Pest PHP Patterns

### Feature Test — Authenticated API Endpoint
```php
<?php

use App\Models\User;
use App\Models\Post;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows an authenticated user to create a post', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/posts', [
            'title' => 'Hello World',
            'body'  => 'Some content.',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Hello World');

    $this->assertDatabaseHas('posts', [
        'title'   => 'Hello World',
        'user_id' => $user->id,
    ]);
});

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/posts', ['title' => 'X'])
         ->assertUnauthorized();
});

it('validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
         ->postJson('/api/posts', [])
         ->assertUnprocessable()
         ->assertJsonValidationErrors(['title', 'body']);
});
```

### Feature Test — Authorization (Policy)
```php
it('prevents a non-owner from deleting a post', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post  = Post::factory()->for($owner)->create();

    $this->actingAs($other)
         ->deleteJson("/api/posts/{$post->id}")
         ->assertForbidden();

    $this->assertDatabaseHas('posts', ['id' => $post->id]);
});
```

### Feature Test — Queue / Mail / Notifications
```php
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use App\Jobs\SendWelcomeEmail;
use App\Mail\OrderConfirmation;
use App\Notifications\InvoiceReady;

it('dispatches a welcome job on registration', function () {
    Queue::fake();

    $this->postJson('/api/register', [
        'name'                  => 'Jane',
        'email'                 => 'jane@example.com',
        'password'              => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertCreated();

    Queue::assertPushed(SendWelcomeEmail::class, fn ($job) =>
        $job->user->email === 'jane@example.com'
    );
});

it('sends an order confirmation email', function () {
    Mail::fake();
    $user  = User::factory()->create();
    $order = Order::factory()->for($user)->create();

    (new OrderService)->complete($order);

    Mail::assertQueued(OrderConfirmation::class, fn ($mail) =>
        $mail->hasTo($user->email)
    );
});
```

### Unit Test — Service Class
```php
<?php

use App\Services\PricingCalculator;

it('applies a percentage discount correctly', function () {
    $calc   = new PricingCalculator();
    $result = $calc->applyDiscount(subtotal: 100.00, discountPercent: 20);

    expect($result)->toBe(80.00);
});

it('does not go below zero for discounts over 100%', function () {
    $calc   = new PricingCalculator();
    $result = $calc->applyDiscount(subtotal: 50.00, discountPercent: 110);

    expect($result)->toBe(0.00);
});
```

### Dataset — Data-Driven Validation Tests
```php
it('rejects invalid email formats', function (string $email) {
    $user = User::factory()->create();

    $this->actingAs($user)
         ->postJson('/api/profile', ['email' => $email])
         ->assertUnprocessable()
         ->assertJsonValidationErrors('email');
})->with([
    'no @ symbol'    => ['notanemail'],
    'missing domain' => ['user@'],
    'empty string'   => [''],
    'spaces'         => ['user @example.com'],
]);
```

### Mocking External Services
```php
use App\Services\StripeGateway;

it('creates an order even when payment gateway is mocked', function () {
    $gateway = Mockery::mock(StripeGateway::class);
    $gateway->shouldReceive('charge')->once()->andReturn(['status' => 'succeeded']);
    $this->app->instance(StripeGateway::class, $gateway);

    $user = User::factory()->create();
    $this->actingAs($user)
         ->postJson('/api/orders', ['plan_id' => 1])
         ->assertCreated();
});
```

---

## PHPUnit Patterns (when requested)

```php
class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
             ->postJson('/api/posts', ['title' => 'Test', 'body' => 'Body'])
             ->assertCreated();

        $this->assertDatabaseHas('posts', ['title' => 'Test', 'user_id' => $user->id]);
    }
}
```

---

## Factory Patterns

Always generate factories alongside tests if they don't exist:

```php
// database/factories/PostFactory.php
public function definition(): array
{
    return [
        'user_id'      => User::factory(),
        'title'        => fake()->sentence(),
        'body'         => fake()->paragraphs(3, true),
        'published_at' => null,
    ];
}

// States for specific scenarios
public function published(): static
{
    return $this->state(['published_at' => now()]);
}

public function draft(): static
{
    return $this->state(['published_at' => null]);
}
```

---

## Checklist Before Delivering Tests

- [ ] `RefreshDatabase` used on all tests touching the DB
- [ ] Factory used — no manual `DB::table()->insert()`
- [ ] Each test has a descriptive name (`it('...')`) that reads as a sentence
- [ ] Only one behavioral focus per test
- [ ] No assertions that always pass (`assertTrue(true)`)
- [ ] Mocks used only for external services — not for internal Laravel code
- [ ] Happy path + at least one failure path covered

## Output Format

Deliver:
1. The complete test file — ready to drop into the project
2. Any factory states or new factories needed
3. A brief note on what each group covers and why

<?php

declare(strict_types=1);

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Facades\Auditor;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Tests\PostModel;
use DevToolbox\Auditor\Tests\UserModel;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests for the fluent ActivityBuilder API.
 *
 * Exercises the full stack: Auditor facade → ActivityBuilder → AuditService → DB.
 */

beforeEach(function () {
    Schema::create('test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->default('secret');
        $table->timestamps();
    });

    Schema::create('test_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->softDeletes();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('test_users');
    Schema::dropIfExists('test_posts');
});

it('creates an activity log record with a log name', function () {
    Auditor::inLog('auth')->log('User logged in');

    $audit = Audit::where('log_name', 'auth')->where('description', 'User logged in')->first();

    expect($audit)->not->toBeNull();
    expect($audit->log_name)->toBe('auth');
    expect($audit->description)->toBe('User logged in');
    expect($audit->event)->toBe(AuditEvent::Activity);
});

it('uses default log name when none is specified', function () {
    Auditor::newActivity()->log('Something happened');

    $audit = Audit::where('description', 'Something happened')->first();
    expect($audit)->not->toBeNull();
    expect($audit->log_name)->toBe('default');
});

it('stores the causedBy model in causer columns', function () {
    $user = UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
    Audit::truncate(); // clear the created event

    Auditor::inLog('auth')->causedBy($user)->log('User performed action');

    $audit = Audit::where('description', 'User performed action')->first();
    expect($audit)->not->toBeNull();
    expect($audit->causer_type)->toBe($user->getMorphClass());
    expect($audit->causer_id)->toBe((string) $user->getKey());
});

it('stores the performedOn model in auditable columns', function () {
    $post = PostModel::create(['title' => 'Hello World']);
    Audit::truncate();

    Auditor::inLog('content')->performedOn($post)->log('Post viewed by admin');

    $audit = Audit::where('description', 'Post viewed by admin')->first();
    expect($audit)->not->toBeNull();
    expect($audit->auditable_type)->toBe($post->getMorphClass());
    expect($audit->auditable_id)->toBe((string) $post->getKey());
});

it('stores custom properties from withProperties()', function () {
    Auditor::inLog('billing')
        ->withProperties(['amount' => 1500, 'currency' => 'USD'])
        ->log('Invoice created');

    $audit = Audit::where('description', 'Invoice created')->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties)->toBe(['amount' => 1500, 'currency' => 'USD']);
});

it('supports withProperty() for setting a single key without overwriting others', function () {
    Auditor::withProperties(['amount' => 500])
        ->withProperty('currency', 'USD')
        ->log('Partial refund');

    $audit = Audit::where('description', 'Partial refund')->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties['amount'])->toBe(500);
    expect($audit->properties['currency'])->toBe('USD');
});

it('chains all methods together into one record', function () {
    $user = UserModel::create(['name' => 'Admin', 'email' => 'admin@test.com']);
    $post = PostModel::create(['title' => 'Invoice #1']);
    Audit::truncate();

    Auditor::inLog('billing')
        ->causedBy($user)
        ->performedOn($post)
        ->withProperties(['amount' => 750, 'plan' => 'pro'])
        ->log('Invoice created');

    expect(Audit::count())->toBe(1);

    $audit = Audit::first();
    expect($audit->log_name)->toBe('billing');
    expect($audit->description)->toBe('Invoice created');
    expect($audit->event)->toBe(AuditEvent::Activity);
    expect($audit->causer_type)->toBe($user->getMorphClass());
    expect($audit->causer_id)->toBe((string) $user->getKey());
    expect($audit->auditable_type)->toBe($post->getMorphClass());
    expect($audit->auditable_id)->toBe((string) $post->getKey());
    expect($audit->properties)->toBe(['amount' => 750, 'plan' => 'pro']);
});

it('starts a chain from causedBy() directly on the facade', function () {
    $user = UserModel::create(['name' => 'Bob', 'email' => 'bob@test.com']);
    Audit::truncate();

    Auditor::causedBy($user)->log('Profile updated');

    $audit = Audit::where('description', 'Profile updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->causer_id)->toBe((string) $user->getKey());
    expect($audit->log_name)->toBe('default');
});

it('starts a chain from performedOn() directly on the facade', function () {
    $post = PostModel::create(['title' => 'My Post']);
    Audit::truncate();

    Auditor::performedOn($post)->log('Post viewed');

    $audit = Audit::where('description', 'Post viewed')->first();
    expect($audit)->not->toBeNull();
    expect($audit->auditable_id)->toBe((string) $post->getKey());
});

it('starts a chain from withProperties() directly on the facade', function () {
    Auditor::withProperties(['key' => 'value'])->log('Custom event');

    $audit = Audit::where('description', 'Custom event')->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties)->toBe(['key' => 'value']);
});

it('does not throw when log() is called with an empty description', function () {
    expect(fn () => Auditor::inLog('auth')->log(''))->not->toThrow(\Throwable::class);
});

it('returns null and does not throw when log() is called twice on the same builder', function () {
    $builder = Auditor::newActivity();
    $first  = $builder->log('First call');
    $second = $builder->log('Second call');

    expect($first)->toBeInstanceOf(Audit::class);
    expect($second)->toBeNull();
    expect(Audit::where('description', 'First call')->count())->toBe(1);
    expect(Audit::where('description', 'Second call')->count())->toBe(0);
});

it('sets user_type and user_id to null on activity records', function () {
    Auditor::inLog('auth')->log('Anon activity');

    $audit = Audit::where('description', 'Anon activity')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_type)->toBeNull();
    expect($audit->user_id)->toBeNull();
});

it('sets old_values and new_values to null on activity records', function () {
    Auditor::inLog('auth')->log('Log only activity');

    $audit = Audit::where('description', 'Log only activity')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values)->toBeNull();
    expect($audit->new_values)->toBeNull();
});

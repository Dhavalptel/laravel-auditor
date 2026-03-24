<?php

declare(strict_types=1);

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Facades\Auditor;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Tests\UserModel;
use DevToolbox\Auditor\Tests\PostModel;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests for new Audit model columns, relationships, and scopes
 * added to support the fluent activity logging API.
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

// -------------------------------------------------------------------------
// causer() relationship
// -------------------------------------------------------------------------

it('resolves the causer() relationship to the correct model', function () {
    $user = UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
    Audit::truncate();

    Auditor::causedBy($user)->log('Profile updated');

    $audit = Audit::first();
    expect($audit->causer)->toBeInstanceOf(UserModel::class);
    expect($audit->causer->id)->toBe($user->id);
});

it('returns null for causer() when causedBy was not set', function () {
    Auditor::inLog('auth')->log('Anonymous action');

    expect(Audit::first()->causer)->toBeNull();
});

// -------------------------------------------------------------------------
// scopeInLog
// -------------------------------------------------------------------------

it('filters audits by a single log name using scopeInLog()', function () {
    Auditor::inLog('auth')->log('Login');
    Auditor::inLog('billing')->log('Invoice');
    Auditor::inLog('auth')->log('Logout');

    expect(Audit::inLog('auth')->count())->toBe(2);
    expect(Audit::inLog('billing')->count())->toBe(1);
});

it('filters audits by multiple log names using scopeInLog()', function () {
    Auditor::inLog('auth')->log('Login');
    Auditor::inLog('billing')->log('Invoice');
    Auditor::inLog('admin')->log('Setting changed');

    expect(Audit::inLog('auth', 'billing')->count())->toBe(2);
});

it('returns zero results for an unknown log name', function () {
    Auditor::inLog('auth')->log('Login');

    expect(Audit::inLog('nonexistent')->count())->toBe(0);
});

// -------------------------------------------------------------------------
// scopeWithDescription
// -------------------------------------------------------------------------

it('filters audits by exact description using scopeWithDescription()', function () {
    Auditor::inLog('auth')->log('User logged in');
    Auditor::inLog('auth')->log('User logged out');
    Auditor::inLog('billing')->log('Invoice created');

    expect(Audit::withDescription('User logged in')->count())->toBe(1);
    expect(Audit::withDescription('Invoice created')->count())->toBe(1);
});

it('returns zero results for a description that does not exist', function () {
    Auditor::inLog('auth')->log('User logged in');

    expect(Audit::withDescription('no match')->count())->toBe(0);
});

// -------------------------------------------------------------------------
// Scope chaining
// -------------------------------------------------------------------------

it('can chain inLog() with event() and other scopes', function () {
    Auditor::inLog('auth')->log('Login attempt');
    UserModel::create(['name' => 'Bob', 'email' => 'bob@test.com']); // creates a 'created' event

    // Only activity events in the auth channel
    $results = Audit::inLog('auth')->event(AuditEvent::Activity)->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->description)->toBe('Login attempt');
});

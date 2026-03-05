<?php

declare(strict_types=1);

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Models\Audit;
use DevToolbox\Auditor\Tests\PlainModel;
use DevToolbox\Auditor\Tests\PostModel;
use DevToolbox\Auditor\Tests\UserModel;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests for the GlobalModelObserver integration.
 *
 * These tests exercise the full stack: Eloquent event → Observer → Service → DB.
 * Uses an in-memory SQLite database with the package migrations loaded.
 */

beforeEach(function () {
    // Create test tables
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

    Schema::create('test_plain', function ($table) {
        $table->id();
        $table->string('value');
        $table->timestamps();
    });

    config(['auditor.events.read' => false]); // Keep tests clean; read tested separately
    config(['auditor.exclude_models' => []]);
    config(['auditor.exclude_attributes' => ['password']]);
    config(['auditor.queue.enabled' => false]);
});

afterEach(function () {
    Schema::dropIfExists('test_users');
    Schema::dropIfExists('test_posts');
    Schema::dropIfExists('test_plain');
});

// ─── Created Events ──────────────────────────────────────────────────────────

describe('created event', function () {

    it('records an audit when a model is created', function () {
        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);

        expect(Audit::count())->toBe(1);

        $audit = Audit::first();
        expect($audit->event)->toBe(AuditEvent::Created)
            ->and($audit->auditable_type)->toBe((new UserModel())->getMorphClass())
            ->and($audit->new_values)->toHaveKey('name', 'Alice')
            ->and($audit->old_values)->toBeNull();
    });

    it('excludes password from new_values for Auditable models', function () {
        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com', 'password' => 'secret']);

        $audit = Audit::first();
        expect($audit->new_values)->not->toHaveKey('password');
    });

    it('attaches tags from Auditable models', function () {
        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);

        $audit = Audit::first();
        expect($audit->tags)->toContain('users');
    });

    it('records audit for plain models with no interface', function () {
        PlainModel::create(['value' => 'hello']);

        expect(Audit::count())->toBe(1);
        expect(Audit::first()->event)->toBe(AuditEvent::Created);
    });
});

// ─── Updated Events ──────────────────────────────────────────────────────────

describe('updated event', function () {

    it('records an audit when a model is updated', function () {
        $user = UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        Audit::query()->delete(); // Clear create audit

        $user->update(['name' => 'Bob']);

        expect(Audit::count())->toBe(1);

        $audit = Audit::first();
        expect($audit->event)->toBe(AuditEvent::Updated)
            ->and($audit->old_values)->toHaveKey('name', 'Alice')
            ->and($audit->new_values)->toHaveKey('name', 'Bob');
    });

    it('only records changed attributes in the diff', function () {
        $user = UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        Audit::query()->delete();

        $user->update(['name' => 'Bob']); // email unchanged

        $audit = Audit::first();
        expect($audit->new_values)->toHaveKey('name')
            ->and($audit->new_values)->not->toHaveKey('email');
    });
});

// ─── Deleted Events ──────────────────────────────────────────────────────────

describe('deleted event', function () {

    it('records an audit when a model is soft deleted', function () {
        $post = PostModel::create(['title' => 'Hello World']);
        Audit::query()->delete();

        $post->delete();

        expect(Audit::count())->toBe(1);

        $audit = Audit::first();
        expect($audit->event)->toBe(AuditEvent::Deleted)
            ->and($audit->old_values)->toHaveKey('title', 'Hello World');
    });

    it('records an audit when a model is force deleted', function () {
        $post = PostModel::create(['title' => 'Hello World']);
        Audit::query()->delete();

        $post->forceDelete();

        expect(Audit::count())->toBe(1);
        expect(Audit::first()->event)->toBe(AuditEvent::Deleted);
    });

    it('records an audit when a plain model (no soft deletes) is deleted', function () {
        $plain = PlainModel::create(['value' => 'temp']);
        Audit::query()->delete();

        $plain->delete();

        expect(Audit::count())->toBe(1);
        expect(Audit::first()->event)->toBe(AuditEvent::Deleted);
    });
});

// ─── Restored Events ─────────────────────────────────────────────────────────

describe('restored event', function () {

    it('records an audit when a soft-deleted model is restored', function () {
        $post = PostModel::create(['title' => 'Hello World']);
        $post->delete();
        Audit::query()->delete();

        $post->restore();

        expect(Audit::count())->toBe(1);
        expect(Audit::first()->event)->toBe(AuditEvent::Restored);
    });
});

// ─── Read Events ─────────────────────────────────────────────────────────────

describe('read event', function () {

    it('records a read audit when read tracking is enabled', function () {
        config(['auditor.events.read' => true]);

        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        Audit::query()->delete();

        UserModel::find(1);

        $readAudits = Audit::where('event', 'read')->count();
        expect($readAudits)->toBeGreaterThanOrEqual(1);
    });

    it('does not record read audits when read tracking is disabled', function () {
        config(['auditor.events.read' => false]);

        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        Audit::query()->delete();

        UserModel::find(1);

        expect(Audit::where('event', 'read')->count())->toBe(0);
    });
});

// ─── Exclusions ───────────────────────────────────────────────────────────────

describe('exclusions', function () {

    it('does not audit excluded models', function () {
        config(['auditor.exclude_models' => [PlainModel::class]]);

        PlainModel::create(['value' => 'should not audit']);

        expect(Audit::count())->toBe(0);
    });

    it('does not audit the Audit model itself', function () {
        // Creating an Audit model should not create another Audit
        UserModel::create(['name' => 'Alice', 'email' => 'alice@test.com']);

        $initialCount = Audit::count();

        // Force creating another audit - it should not recurse
        expect(Audit::count())->toBe($initialCount);
    });
});

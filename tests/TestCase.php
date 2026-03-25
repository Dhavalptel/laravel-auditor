<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Tests;

use DevToolbox\Auditor\AuditorServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base test case for all package tests.
 *
 * Sets up the in-memory SQLite database, runs package migrations,
 * and registers the service provider.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Registers the package service provider with the test application.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AuditorServiceProvider::class,
        ];
    }

    /**
     * Configures the test environment.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        // Use in-memory SQLite for speed
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Disable queueing so audit writes happen synchronously in tests
        $app['config']->set('auditor.queue.enabled', false);

        // Disable read tracking by default in tests (override per test if needed)
        $app['config']->set('auditor.events.read', false);

        // Disable the raw DB query listener in tests to prevent it from creating
        // duplicate audit records alongside the Eloquent observer.
        $app['config']->set('auditor.db_listener.enabled', false);
    }

    /**
     * Runs the package migrations for the test database.
     *
     * @return void
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}

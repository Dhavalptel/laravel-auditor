<?php

declare(strict_types=1);

namespace DevToolbox\Auditor;

use DevToolbox\Auditor\Commands\AuditorPruneCommand;
use DevToolbox\Auditor\Observers\GlobalModelObserver;
use DevToolbox\Auditor\Resolvers\UserResolver;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the Auditor package.
 *
 * Responsible for:
 *  - Publishing config and migration files
 *  - Binding the AuditService and UserResolver in the container
 *  - Registering the GlobalModelObserver on the base Eloquent Model
 *  - Registering the `auditor:prune` Artisan command
 */
class AuditorServiceProvider extends ServiceProvider
{
    /**
     * Registers package bindings into the service container.
     *
     * Binds UserResolver as a singleton so the same resolver instance
     * is reused across all audit events in a single request lifecycle.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__ . '/../config/auditor.php',
            key: 'auditor',
        );

        // Bind UserResolver as singleton — allow overriding in app service providers
        $this->app->singleton(UserResolver::class, UserResolver::class);

        // Bind AuditService as singleton for the full request lifecycle
        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService($app->make(UserResolver::class));
        });
    }

    /**
     * Bootstraps package services after all providers have registered.
     *
     * Registers the global model observer and publishes package assets.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishAssets();
        $this->loadMigrations();
        $this->registerCommands();
        $this->registerGlobalObserver();
    }

    /**
     * Publishes config and migration files for the host application.
     *
     * Run: php artisan vendor:publish --provider="DevToolbox\Auditor\AuditorServiceProvider"
     *
     * @return void
     */
    protected function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/auditor.php' => config_path('auditor.php'),
        ], 'auditor-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'auditor-migrations');
    }

    /**
     * Loads migrations directly from the package directory.
     *
     * Allows migration to run without publishing, for simpler setups.
     *
     * @return void
     */
    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Registers the package's Artisan commands.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditorPruneCommand::class,
            ]);
        }
    }

    /**
     * Registers the GlobalModelObserver on the base Eloquent Model class.
     *
     * This is what makes the package "zero-touch" — by observing the base
     * Model class, every Eloquent model in the application is automatically
     * covered without any trait or interface required.
     *
     * The observer is only registered if auditing is enabled in config.
     *
     * @return void
     */
    protected function registerGlobalObserver(): void
    {
        if (! config('auditor.enabled', true)) {
            return;
        }

        $observer = $this->app->make(GlobalModelObserver::class);
        $events   = $this->app->make('events');

        foreach (['created', 'retrieved', 'updated', 'deleted', 'restored', 'forceDeleted'] as $event) {
            $events->listen("eloquent.{$event}: *", function (string $eventName, array $payload) use ($observer, $event): void {
                $model = $payload[0] ?? null;

                if (! $model instanceof Model) {
                    return;
                }

                $observer->{$event}($model);
            });
        }
    }
}

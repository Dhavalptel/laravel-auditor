<?php

declare(strict_types=1);

namespace DevToolbox\Auditor;

use DevToolbox\Auditor\Commands\AuditorPruneCommand;
use DevToolbox\Auditor\Listeners\DatabaseQueryListener;
use DevToolbox\Auditor\Observers\GlobalModelObserver;
use DevToolbox\Auditor\Resolvers\TableModelResolver;
use DevToolbox\Auditor\Resolvers\UserResolver;
use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the Auditor package.
 *
 * Responsible for:
 *  - Publishing config and migration files
 *  - Binding the AuditService, UserResolver, and TableModelResolver
 *  - Registering the GlobalModelObserver for Eloquent events
 *  - Registering the DatabaseQueryListener for raw DB::table() queries
 *  - Registering the `auditor:prune` Artisan command
 */
class AuditorServiceProvider extends ServiceProvider
{
    /**
     * Registers package bindings into the service container.
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

        // Bind TableModelResolver as singleton — builds the table->class map once per request
        $this->app->singleton(TableModelResolver::class, TableModelResolver::class);

        // Bind AuditService as singleton for the full request lifecycle
        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService($app->make(UserResolver::class));
        });
    }

    /**
     * Bootstraps package services after all providers have registered.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishAssets();
        $this->loadMigrations();
        $this->registerCommands();
        $this->registerGlobalObserver();
        $this->registerDatabaseQueryListener();
    }

    /**
     * Publishes config and migration files for the host application.
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
     * Registers the GlobalModelObserver by listening to Eloquent's fired events
     * via the application event dispatcher.
     *
     * We cannot call Model::observe() on the abstract base class directly —
     * Laravel tries to instantiate it internally which throws an error.
     * Instead, we listen to the wildcard eloquent.* events on the dispatcher,
     * which fires for every model in the application automatically.
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

    /**
     * Registers the DatabaseQueryListener on Laravel's QueryExecuted event.
     *
     * This captures all raw DB::table() queries that bypass Eloquent.
     * Only registered when `auditor.db_listener.enabled` is true.
     *
     * Note: The listener internally guards against auditing its own
     * SELECT queries (used to capture old values) via a flag, preventing
     * infinite recursion.
     *
     * @return void
     */
    protected function registerDatabaseQueryListener(): void
    {
        if (! config('auditor.enabled', true)) {
            return;
        }

        if (! config('auditor.db_listener.enabled', true)) {
            return;
        }

        $listener = $this->app->make(DatabaseQueryListener::class);

        $this->app->make('events')->listen(
            QueryExecuted::class,
            fn(QueryExecuted $event) => $listener->handle($event),
        );
    }
}
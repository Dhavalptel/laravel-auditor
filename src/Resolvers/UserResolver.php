<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Resolvers;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the currently authenticated user for audit attribution.
 *
 * By default, the resolver checks all guards defined in your auth config.
 * You may replace this resolver entirely by binding your own implementation
 * in the service container, or by setting `auditor.user_resolver` in config.
 *
 * Custom resolver example:
 *
 * ```php
 * // In AppServiceProvider::register()
 * $this->app->bind(UserResolver::class, function () {
 *     return new class extends UserResolver {
 *         public function resolve(): ?Model
 *         {
 *             return MyCustomAuth::user(); // your custom logic
 *         }
 *     };
 * });
 * ```
 */
class UserResolver
{
    /**
     * Resolves and returns the currently authenticated user model.
     *
     * Iterates over all configured auth guards and returns the first
     * authenticated user it finds. Returns null if no user is authenticated
     * or if the application is running in a console context.
     *
     * @return Model|null The authenticated user model, or null.
     */
    public function resolve(): ?Model
    {
        if (app()->runningInConsole()) {
            return null;
        }

        try {
            $guards = array_keys(config('auth.guards', []));

            foreach ($guards as $guard) {
                $user = auth()->guard($guard)->user();

                if ($user instanceof Model) {
                    return $user;
                }
            }
        } catch (\Throwable) {
            // Auth may not be available in all contexts (e.g. early boot, testing)
        }

        return null;
    }
}

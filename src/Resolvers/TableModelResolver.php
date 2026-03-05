<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Resolves a database table name to its corresponding Eloquent model class.
 *
 * Used when auditing raw DB::table() queries where no model instance exists.
 * Scans the configured model paths, instantiates each model, and checks
 * if its table name matches the queried table.
 *
 * Results are cached in-memory for the lifetime of the request to avoid
 * repeated filesystem scans on high-volume queries.
 *
 * Example:
 *   'archives' → App\Models\Archive::class
 *   'unknown_table' → 'unknown_table' (fallback to table name)
 */
class TableModelResolver
{
    /**
     * In-memory cache of resolved table → class mappings.
     *
     * @var array<string, string>
     */
    protected array $cache = [];

    /**
     * Whether the model map has been built for this request.
     */
    protected bool $mapBuilt = false;

    /**
     * Resolves a table name to a fully-qualified model class name.
     *
     * Returns the model class if found, otherwise returns the table name as-is.
     *
     * @param  string  $table  The database table name.
     * @return string          Model class name or table name fallback.
     */
    public function resolve(string $table): string
    {
        if (! $this->mapBuilt) {
            $this->buildMap();
        }

        return $this->cache[$table] ?? $table;
    }

    /**
     * Scans all configured model paths and builds the table → class map.
     *
     * @return void
     */
    protected function buildMap(): void
    {
        $this->mapBuilt = true;

        $paths = config('auditor.db_listener.model_paths', [app_path('Models')]);

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $class = $this->pathToClass($file->getPathname());

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                try {
                    $model = new $class();

                    if ($model instanceof Model) {
                        $this->cache[$model->getTable()] = $model->getMorphClass();
                    }
                } catch (\Throwable) {
                    // Skip models that can't be instantiated without context
                }
            }
        }
    }

    /**
     * Converts an absolute file path to a fully-qualified class name.
     *
     * Assumes PSR-4 autoloading with the app namespace rooted at app_path().
     *
     * @param  string  $path  Absolute path to a PHP file.
     * @return string|null    Fully-qualified class name, or null if not resolvable.
     */
    protected function pathToClass(string $path): ?string
    {
        if (! str_ends_with($path, '.php')) {
            return null;
        }

        $relative = str_replace(
            [app_path() . DIRECTORY_SEPARATOR, '/', '\\', '.php'],
            ['', '\\', '\\', ''],
            $path,
        );

        return rtrim(app()->getNamespace(), '\\') . '\\' . ltrim($relative, '\\');
    }
}
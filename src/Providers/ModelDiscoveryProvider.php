<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use ReflectionClass;

use function array_values;
use function class_exists;
use function file_get_contents;
use function get_declared_classes;
use function is_a;
use function is_array;
use function is_dir;
use function is_string;
use function array_diff;
use function in_array;
use function preg_match;
use function realpath;
use function str_starts_with;

/**
 * Discovers Eloquent model classes from the project.
 *
 * Scans configured directories for PHP files, loads them via Composer's autoloader,
 * and filters for Model subclasses.
 *
 * @internal
 */
final class ModelDiscoveryProvider
{
    /** @var list<class-string<Model>> */
    private static array $modelClasses = [];

    public static function discoverModels(Application $app): void
    {
        $directories = self::resolveModelDirectories($app);

        /** @var list<class-string<Model>> $models */
        $models = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $phpFiles = self::findPhpFiles($directory);
            if ($phpFiles === []) {
                continue;
            }

            foreach ($phpFiles as $file) {
                $className = self::extractClassName($file);
                if ($className === null) {
                    continue;
                }

                // Use Composer's autoloader to load the class safely
                // class_exists() triggers autoloading without fatal errors
                try {
                    if (!class_exists($className, true)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                if (!is_a($className, Model::class, true)) {
                    continue;
                }

                try {
                    $reflection = new ReflectionClass($className);
                } catch (\ReflectionException) {
                    continue;
                }

                if ($reflection->isAbstract()) {
                    continue;
                }

                if (!in_array($className, $models, true)) {
                    $models[] = $className;
                }
            }
        }

        // Also check already-loaded classes (from Composer's classmap)
        foreach (get_declared_classes() as $class) {
            if (!is_a($class, Model::class, true)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (\ReflectionException) {
                continue;
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            // Only include classes from the configured directories
            $fileName = $reflection->getFileName();
            if (!is_string($fileName)) {
                continue;
            }

            foreach ($directories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }

                $realDir = realpath($directory);
                if ($realDir !== false && str_starts_with($fileName, $realDir)) {
                    if (!in_array($class, $models, true)) {
                        $models[] = $class;
                    }
                    break;
                }
            }
        }

        self::$modelClasses = $models;
    }

    /**
     * @return list<class-string<Model>>
     * @psalm-external-mutation-free
     */
    public static function getModelClasses(): array
    {
        return self::$modelClasses;
    }

    /** @return list<string> */
    private static function resolveModelDirectories(Application $app): array
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // Check plugin-specific config first
        /** @var mixed $locations */
        $locations = $config->get('psalm-laravel.model_locations');

        if (!is_array($locations) || $locations === []) {
            // Fall back to ide-helper config
            /** @var mixed $locations */
            $locations = $config->get('ide-helper.model_locations');
        }

        if (!is_array($locations) || $locations === []) {
            // Default locations
            $locations = [
                $app->path('Models'),
                $app->path(),
            ];
        }

        /** @var list<string> $directories */
        $directories = [];

        foreach ($locations as $location) {
            if (!is_string($location)) {
                continue;
            }

            // If relative, resolve against app base path
            if (!str_starts_with($location, '/')) {
                $location = $app->basePath($location);
            }

            $directories[] = $location;
        }

        return $directories;
    }

    /**
     * Extract the fully qualified class name from a PHP file by parsing
     * the namespace and class declarations.
     *
     * @return class-string|null
     */
    private static function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^\s;{]+)/m', $contents, $matches)) {
            $namespace = $matches[1] . '\\';
        }

        if (preg_match('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/m', $contents, $matches)) {
            /** @var class-string */
            return $namespace . $matches[1];
        }

        return null;
    }

    /**
     * Recursively find all .php files in a directory.
     *
     * @return list<string>
     */
    private static function findPhpFiles(string $directory): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            if (!is_string($realPath)) {
                continue;
            }

            $files[] = $realPath;
        }

        return $files;
    }
}

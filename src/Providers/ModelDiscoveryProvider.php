<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;

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

        /** @var array<class-string<Model>, class-string<Model>> $models */
        $models = [];

        foreach ($directories as $directory) {
            if (!\is_dir($directory)) {
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

                // Trigger Composer's autoloader to load the class; the try/catch
                // guards against files that fail to compile or have missing dependencies
                try {
                    if (!\class_exists($className, true)) {
                        continue;
                    }
                } catch (\Error) {
                    continue;
                }

                if (self::isConcreteModel($className)) {
                    /** @var class-string<Model> $className */
                    $models[$className] = $className;
                }
            }
        }

        // Also check already-loaded classes (from Composer's classmap)
        foreach (\get_declared_classes() as $class) {
            if (!self::isConcreteModel($class)) {
                continue;
            }

            // Only include classes from the configured directories
            $reflection = new \ReflectionClass($class);
            $fileName = $reflection->getFileName();
            if (!\is_string($fileName)) {
                continue;
            }

            foreach ($directories as $directory) {
                if (!\is_dir($directory)) {
                    continue;
                }

                $realDir = \realpath($directory);
                if ($realDir !== false && \str_starts_with($fileName, $realDir)) {
                    /** @var class-string<Model> $class */
                    $models[$class] = $class;
                    break;
                }
            }
        }

        $modelList = \array_values($models);
        \sort($modelList); // for better DX
        self::$modelClasses = $modelList;
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

        if (!\is_array($locations) || $locations === []) {
            // Fall back to ide-helper config
            /** @var mixed $locations */
            $locations = $config->get('ide-helper.model_locations');
        }

        if (!\is_array($locations) || $locations === []) {
            // Default locations
            $locations = [
                $app->path('Models'),
                $app->path(),
            ];
        }

        /** @var list<string> $directories */
        $directories = [];

        foreach ($locations as $location) {
            if (!\is_string($location)) {
                continue;
            }

            // If relative, resolve against app base path
            if (!\str_starts_with($location, '/') && !\str_starts_with($location, \DIRECTORY_SEPARATOR) && !\preg_match('/^[a-zA-Z]:/', $location)) {
                $location = $app->basePath($location);
            }

            $directories[] = $location;
        }

        return $directories;
    }

    /**
     * Check whether a class is a concrete (non-abstract) Eloquent Model subclass.
     *
     * @param class-string $className
     * @psalm-suppress MissingPureAnnotation uses reflection which has side effects
     */
    private static function isConcreteModel(string $className): bool
    {
        if (!\is_a($className, Model::class, true)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return false;
        }

        return !$reflection->isAbstract();
    }

    /**
     * Extract the fully qualified class name from a PHP file using token_get_all().
     *
     * This correctly handles comments, strings, and all class modifier keywords
     * (abstract, final, readonly).
     */
    private static function extractClassName(string $filePath): ?string
    {
        $contents = @\file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $tokens = \token_get_all($contents);
        $count = \count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === \T_NAMESPACE) {
                $namespace = self::parseNamespace($tokens, $i, $count);
                continue;
            }

            if ($tokens[$i][0] === \T_CLASS) {
                $name = self::parseClassName($tokens, $i, $count);
                if ($name !== null) {
                    return $namespace !== '' ? $namespace . '\\' . $name : $name;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function parseNamespace(array $tokens, int &$i, int $count): string
    {
        $namespace = '';
        $i++;

        for (; $i < $count; $i++) {
            if (!\is_array($tokens[$i])) {
                break;
            }

            if ($tokens[$i][0] === \T_WHITESPACE) {
                continue;
            }

            if ($tokens[$i][0] === \T_STRING || $tokens[$i][0] === \T_NAME_QUALIFIED) {
                $namespace .= $tokens[$i][1];
            } else {
                break;
            }
        }

        return $namespace;
    }

    /**
     * @psalm-pure
     *
     * @param array<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function parseClassName(array $tokens, int $i, int $count): ?string
    {
        // Skip whitespace after 'class' keyword to find the class name
        $i++;
        for (; $i < $count; $i++) {
            if (!\is_array($tokens[$i])) {
                return null;
            }

            if ($tokens[$i][0] === \T_WHITESPACE) {
                continue;
            }

            if ($tokens[$i][0] === \T_STRING) {
                return $tokens[$i][1];
            }

            return null;
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

            if (!\is_string($realPath)) {
                continue;
            }

            $files[] = $realPath;
        }

        return $files;
    }
}

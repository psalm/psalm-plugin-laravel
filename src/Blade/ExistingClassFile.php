<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Composer\Autoload\ClassLoader;

/**
 * The file that already defines a class independently of any template (#2126), by the same three
 * routes Psalm's own resolution has: Composer, then the classmap, then what the booted application
 * already declared.
 *
 * PHP class names are case-insensitive and Psalm keys its storage by the lowercased name, so the
 * classmap is matched that way. Composer's PSR-4 lookup builds a path from the name as written, so a
 * differently cased spelling of an unloaded PSR-4 class is only found on a case-insensitive
 * filesystem; the rest is a documented limit.
 *
 * @internal
 */
final class ExistingClassFile
{
    /**
     * @param string                    $className      fully-qualified, without a leading backslash
     * @param \Closure(string): (string|false) $composerLookup Psalm's configured Composer resolution
     *
     * @return string|null a normalized path; null when nothing outside the templates defines it, and
     *                     for a class PHP itself declares, which has no file
     */
    public static function find(string $className, \Closure $composerLookup): ?string
    {
        $file = $composerLookup($className);

        if (\is_string($file)) {
            return self::normalize($file);
        }

        $lowercased = \strtolower($className);

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getClassMap() as $mappedClass => $mappedFile) {
                if (\strtolower($mappedClass) === $lowercased) {
                    return self::normalize($mappedFile);
                }
            }
        }

        if (!self::isDeclared($className)) {
            return null;
        }

        /** @var class-string $declared proven by the check above */
        $declared = $className;
        $declaringFile = (new \ReflectionClass($declared))->getFileName();

        return $declaringFile === false ? null : $declaringFile;
    }

    /** Never autoloads: this mirrors what Psalm's scanner will see rather than forcing a resolution. */
    public static function isDeclared(string $className): bool
    {
        return \class_exists($className, false)
            || \interface_exists($className, false)
            || \trait_exists($className, false)
            || \enum_exists($className, false);
    }

    /** Composer's loaders hand back `vendor/composer/../../app/Foo.php`, which would reach a message verbatim. */
    private static function normalize(string $file): string
    {
        $resolved = \realpath($file);

        return $resolved === false ? $file : $resolved;
    }
}

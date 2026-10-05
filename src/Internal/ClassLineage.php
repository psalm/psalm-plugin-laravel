<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;

/**
 * Class ancestry from Psalm's class storage, never PHP's autoloader.
 *
 * `\is_a($class, X::class, true)` on a class name taken from an analyzed type loads that class's file.
 * Psalm's error handler stays active after the plugin boots (and in forked workers), so a load-time
 * deprecation or warning in the analyzed project becomes a thrown exception that ends the whole run
 * (#1253, #1652).
 *
 * @internal
 * @psalm-immutable
 */
final class ClassLineage
{
    /**
     * $class is $ancestor, extends it, or implements it (an interface: extends it).
     *
     * Reads the populated storage maps directly rather than `Codebase::classExtendsOrImplements()`:
     * - it never throws (that API throws InvalidArgumentException on a class Psalm never scanned, so every
     *   caller needs a catch);
     * - it records no code_use_graph reference, unlike `Codebase::classExists()`;
     * - it answers `I2 extends I1` and `Model is Model`, where `classExtendsOrImplements()` returns false
     *   (`classExtends()` is non-reflexive and `classImplements()` ignores interface parents).
     *
     * Both names go through {@see canonicalName()}. Psalm keys the ancestry maps by the name a declaration
     * wrote, so `implements SomeAlias` is stored under the alias: when the direct lookup misses, each entry
     * is canonicalized too (the miss path only, so the common hit stays a single isset). A class Psalm never
     * scanned is "not proven" (false).
     *
     * @template T of object
     * @param class-string<T> $ancestor
     * @psalm-assert-if-true class-string<T> $class
     * @psalm-mutation-free
     */
    public static function isA(Codebase $codebase, string $class, string $ancestor): bool
    {
        $ancestorLc = \strtolower(self::canonicalName($codebase, $ancestor));
        $class = self::canonicalName($codebase, $class);
        if (\strtolower($class) === $ancestorLc) {
            return true;
        }

        $storageProvider = $codebase->classlike_storage_provider;
        if (!$storageProvider->has($class)) {
            return false;
        }

        $storage = $storageProvider->get($class);

        if (
            isset($storage->parent_classes[$ancestorLc])
            || isset($storage->class_implements[$ancestorLc])
            || isset($storage->parent_interfaces[$ancestorLc])
        ) {
            return true;
        }

        foreach ([$storage->parent_classes, $storage->class_implements, $storage->parent_interfaces] as $ancestry) {
            foreach ($ancestry as $name) {
                if (\strtolower(self::canonicalName($codebase, $name)) === $ancestorLc) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Storage for $class under its canonical name, or null when Psalm never scanned it.
     *
     * @psalm-mutation-free
     */
    public static function storage(Codebase $codebase, string $class): ?ClassLikeStorage
    {
        $class = self::canonicalName($codebase, $class);
        $storageProvider = $codebase->classlike_storage_provider;

        return $storageProvider->has($class) ? $storageProvider->get($class) : null;
    }

    /**
     * The name storage is keyed by: no leading backslash (a valid spelling in cast strings and container
     * abstracts), and `class_alias()` aliases resolved. Psalm keeps an alias in method storage return
     * types, so an alias reaches handlers.
     *
     * @psalm-mutation-free
     */
    public static function canonicalName(Codebase $codebase, string $class): string
    {
        return $codebase->classlikes->getUnAliasedName(\ltrim($class, '\\'));
    }
}

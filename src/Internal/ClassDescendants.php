<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Storage\ClassLikeStorage;

/**
 * The classes Psalm scanned that extend or implement a given class, for a proof that "no subclass overrides X".
 *
 * Psalm's `dependent_classlikes` is not transitively closed, so this walks the populated storages' own
 * ancestry maps instead (through {@see ClassLineage::isA()}, which also follows aliases). A class Psalm did not
 * scan (vendor code it never loaded, a generated or anonymous class, a runtime subclass) is invisible to it: the
 * result is "no scanned descendant does", never "no descendant does".
 *
 * One pass over every storage per distinct class, memoized for the run. Only the Psalter fixes ask, after
 * `shouldFixIssue()` said yes, so a plain Psalm run never pays for it.
 *
 * @internal
 */
final class ClassDescendants
{
    /** @var array<lowercase-string, list<ClassLikeStorage>|null> */
    private static array $cache = [];

    /** Registered in `Plugin::resetInvocationState()`: the answer belongs to one codebase. */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Concrete and abstract classes (never an interface or a trait) that descend from $class, itself excluded.
     * Null when that cannot be proven: $class has no populated storage, or a storage that might descend from it
     * is not populated yet.
     *
     * @return list<ClassLikeStorage>|null
     */
    public static function of(Codebase $codebase, string $class): ?array
    {
        $key = \strtolower($class);

        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            return self::$cache[$key] = self::scan($codebase, $class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            // Storage read before population: not proven, so the caller declines.
            return self::$cache[$key] = null;
        }
    }

    /**
     * @return list<ClassLikeStorage>|null
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private static function scan(Codebase $codebase, string $class): ?array
    {
        $target = ClassLineage::storage($codebase, $class);

        if (!$target instanceof ClassLikeStorage || !$target->populated) {
            return null;
        }

        /** @psalm-var class-string $targetName */
        $targetName = $target->name;
        $descendants = [];

        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            if ($storage->is_interface || $storage->is_trait || \strcasecmp($storage->name, $target->name) === 0) {
                continue;
            }

            if (!$storage->populated) {
                // Its ancestry maps are empty until populated, so it cannot be ruled out.
                return null;
            }

            if (ClassLineage::isA($codebase, $storage->name, $targetName)) {
                $descendants[] = $storage;
            }
        }

        return $descendants;
    }
}

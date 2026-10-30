<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;

/**
 * Spots a scanned class that PHP cannot compile, so the plugin must not autoload it.
 *
 * A compile-time fatal bypasses `catch (\Throwable)` and kills the whole Psalm run, so
 * `class_exists()` / `is_subclass_of()` probes have to be guarded BEFORE they run (#2136).
 * Only one shape is detected: the class's short name clashes with a `use` import
 * (`use Illuminate\Support\Facades\Cache; class Cache extends Cache {}`), the one fatal Psalm
 * records from scan-time state. Psalm reports the file itself as a ParseError.
 *
 * `has_visitor_issues` is not a usable signal: Psalm also sets it on the surviving storage of a
 * DuplicateClass, which would drop every class a stray re-declaration shadows. See
 * docs/contributing/decisions.md, "Classes PHP cannot compile are skipped before autoload (#2136)".
 *
 * @internal
 * @psalm-immutable
 */
final class UnloadableClassDetector
{
    /**
     * A human-readable reason PHP cannot load the class, or null when none is proven.
     *
     * Ancestors are checked too: autoloading the class compiles its parents first.
     *
     * @psalm-capabilities read-props
     */
    public static function reason(Codebase $codebase, ClassLikeStorage $storage): ?string
    {
        $own = self::importClash($storage);

        if ($own !== null) {
            return $own;
        }

        foreach ($storage->parent_classes as $parentName) {
            try {
                $parent = $codebase->classlike_storage_provider->get($parentName);
            } catch (\InvalidArgumentException) {
                // Unscanned ancestor: not proven broken.
                continue;
            }

            $inherited = self::importClash($parent);

            if ($inherited !== null) {
                return "its parent class '{$parent->name}' is unloadable: {$inherited}";
            }
        }

        return null;
    }

    /** @psalm-capabilities read-props */
    private static function importClash(ClassLikeStorage $storage): ?string
    {
        // A stub re-declaration replaces the class's aliases with the stub's imports (ClassLikeNodeScanner),
        // which the runtime file never sees: a stubbed storage proves nothing about what PHP compiles.
        if ($storage->stubbed) {
            return null;
        }

        $separator = \strrpos($storage->name, '\\');
        $shortName = $separator === false ? $storage->name : \substr($storage->name, $separator + 1);
        $imported = $storage->aliases?->uses[\strtolower($shortName)] ?? null;

        // PHP compares class names case-insensitively, unlike Psalm's own check.
        if ($imported === null || \strcasecmp($imported, $storage->name) === 0) {
            return null;
        }

        return "class name '{$shortName}' clashes with the import 'use {$imported};', PHP cannot load the class";
    }
}

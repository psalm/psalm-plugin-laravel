<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * The analyzed project's Composer install root, and whether a path sits inside it.
 *
 * The boundary is that root, never the literal substring `vendor`. Both failures of the naive
 * test are silent and point in opposite directions: `config.vendor-dir` can rename the directory,
 * after which nothing is recognised as vendor code, and a project whose own path carries a
 * `vendor` segment (`/srv/vendor/shop/...`) has all of its code mistaken for vendor code. A
 * project's published view override, `resources/views/vendor/<namespace>`, is the everyday shape
 * of the second case.
 *
 * @internal
 */
final class VendorDirectory
{
    /**
     * Derived from where Composer installed laravel/framework — every booted Laravel app has one.
     * Null when it cannot be determined (`composer/composer` runtime API missing, or
     * laravel/framework not resolvable), in which case callers skip their vendor filter rather
     * than guess at it.
     */
    public static function path(): ?string
    {
        if (!\class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        try {
            $installPath = \Composer\InstalledVersions::getInstallPath('laravel/framework');
        } catch (\Throwable) {
            return null;
        }

        // vendor/laravel/framework -> vendor
        return $installPath === null ? null : \dirname($installPath, 2);
    }

    /**
     * Both sides are resolved first: a symlinked path-repository package reports its real source
     * location, and the trailing separator is what stops a sibling such as `vendor-extra/` from
     * matching the prefix. An unresolvable path is treated as outside.
     */
    public static function contains(string $path, string $vendorDir): bool
    {
        $resolvedPath = \realpath($path);
        $resolvedVendorDir = \realpath($vendorDir);

        if ($resolvedPath === false || $resolvedVendorDir === false) {
            return false;
        }

        return \str_starts_with($resolvedPath, \rtrim($resolvedVendorDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR);
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Composer\InstalledVersions;

/**
 * Identifies the installed plugin build for cache keys.
 *
 * The Composer version alone is the same for every commit of a dev branch (`4.x-dev`), so the
 * commit reference Composer records for VCS installs is appended. It does not change for local
 * edits made through a path repository or in the plugin's own checkout until `composer install`
 * runs again; clear the plugin cache directory in that case.
 *
 * @internal
 */
final class PluginBuildId
{
    private const PACKAGE = 'psalm/plugin-laravel';

    public static function current(): string
    {
        return self::of(
            InstalledVersions::getVersion(self::PACKAGE),
            InstalledVersions::getReference(self::PACKAGE),
        );
    }

    /** @psalm-pure */
    public static function of(?string $version, ?string $reference): string
    {
        return ($version ?? 'unknown') . ($reference !== null ? '@' . $reference : '');
    }
}

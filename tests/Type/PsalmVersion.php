<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use Composer\InstalledVersions;

/**
 * Psalm-version gate for phpt tests broken by a specific Psalm release, called from a phpt
 * `--SKIPIF--` section.
 *
 * Unlike {@see LaravelVersion}, which gates on a permanent capability boundary, this gate marks a
 * single defective release range. The upper bound is what keeps it honest: the moment a Psalm
 * outside the range is installed the test runs again, and fails again if the bug is still there.
 * An open-ended `skipFrom` would go stale silently.
 *
 * The `--SKIPIF--` script runs in a bare `php` process from the project-root working directory with
 * no autoloader preloaded, so require it via `getcwd()`:
 *
 *   --SKIPIF--
 *   <?php
 *   require getcwd() . '/vendor/autoload.php';
 *   \Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta22');
 */
final class PsalmVersion
{
    /**
     * Skip while the installed Psalm is in `[$brokenFrom, $fixedFrom)`.
     *
     * Both bounds are required. `$fixedFrom` is the next release after the known-broken one, not a
     * release known to carry the fix: the point is to re-enable the test as soon as the version
     * moves, so that whoever bumps Psalm finds out whether the bug survived.
     */
    public static function skipOnRange(string $brokenFrom, string $fixedFrom): void
    {
        $installed = InstalledVersions::getPrettyVersion('vimeo/psalm');

        if ($installed === null) {
            return;
        }

        if (\version_compare($installed, $brokenFrom, '>=') && \version_compare($installed, $fixedFrom, '<')) {
            echo 'skip broken on Psalm ' . $installed . ' (>= ' . $brokenFrom . ', < ' . $fixedFrom . ')';
        }
    }
}

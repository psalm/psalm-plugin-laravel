<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

/**
 * Carbon-version gate for version-specific stub tests, called from a phpt `--SKIPIF--` section.
 *
 * `CarbonStubProvider` registers different stub sets per installed Carbon major:
 * the `CarbonPeriod` / `DatePeriodBase` stubs and the `WeekDay`-typed dual-purpose
 * narrowings only exist on Carbon 3 (see `CarbonStubProvider::register()`). A phpt
 * asserting Carbon-3 shapes must skip on Carbon 2, and a phpt asserting the Carbon-2
 * baseline must skip on Carbon 3, otherwise it fails on the other cell of the test matrix.
 *
 * Unlike {@see LaravelVersion} (which reads `Application::VERSION`), Carbon exposes no version
 * constant usable across both majors, so the version is resolved from Composer with the same
 * `InstalledVersions::satisfies()` call `CarbonStubProvider` gates on. The `--SKIPIF--` script
 * runs in a bare `php` process from the project root with no autoloader preloaded, so require it
 * via `getcwd()`:
 *
 *   --SKIPIF--
 *   <?php
 *   require getcwd() . '/vendor/autoload.php';
 *   \Tests\Psalm\LaravelPlugin\Type\CarbonVersion::skipBelow('3.0.0');
 *
 * Each method echoes a `skip ...` message (which the runner detects) when the test must not run.
 */
final class CarbonVersion
{
    /** Skip when the installed Carbon is older than $version (the Carbon-3 stubs do not load there). */
    public static function skipBelow(string $version): void
    {
        if (InstalledVersions::satisfies(new VersionParser(), 'nesbot/carbon', '<' . $version)) {
            echo 'skip needs Carbon >= ' . $version
                . ' (installed ' . InstalledVersions::getPrettyVersion('nesbot/carbon') . ')';
        }
    }

    /** Skip when the installed Carbon is $version or newer (for the Carbon-2 baseline only). */
    public static function skipFrom(string $version): void
    {
        if (InstalledVersions::satisfies(new VersionParser(), 'nesbot/carbon', '>=' . $version)) {
            echo 'skip needs Carbon < ' . $version
                . ' (installed ' . InstalledVersions::getPrettyVersion('nesbot/carbon') . ')';
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use Illuminate\Foundation\Application;

/**
 * Laravel-version gate for version-specific stub tests, called from a phpt `--SKIPIF--` section.
 *
 * Stubs under `stubs/<version>/` load only when the installed Laravel is `>=` that version
 * (see `StubFileFinder::stubsForLaravelVersion`). A phpt asserting such a stub must skip when the
 * installed Laravel is older, otherwise it fails on the lower cells of the CI matrix
 * (`.github/workflows/tests.yml` runs `test:type` over `^13.0` and `^12.4`, including `prefer-lowest`).
 *
 * The `--SKIPIF--` script runs in a bare `php` process from the project-root working directory with
 * no autoloader preloaded, so require it via `getcwd()`:
 *
 *   --SKIPIF--
 *   <?php
 *   require getcwd() . '/vendor/autoload.php';
 *   \Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.42.0');
 *
 * Each method echoes a `skip ...` message (which the runner detects) when the test must not run.
 */
final class LaravelVersion
{
    /** Skip when the installed Laravel is older than $version (the stub dir does not load there). */
    public static function skipBelow(string $version): void
    {
        if (\version_compare(Application::VERSION, $version, '<')) {
            echo 'skip needs Laravel >= ' . $version . ' (installed ' . Application::VERSION . ')';
        }
    }

    /** Skip when the installed Laravel is $version or newer (for behavior only present on older lines). */
    public static function skipFrom(string $version): void
    {
        if (\version_compare(Application::VERSION, $version, '>=')) {
            echo 'skip needs Laravel < ' . $version . ' (installed ' . Application::VERSION . ')';
        }
    }
}

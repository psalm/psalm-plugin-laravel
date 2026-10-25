<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * Shares one opcache file cache between every `vendor/bin/psalm` child the test run forks.
 *
 * Each child is a fresh PHP process that recompiles Psalm, vendor, Laravel and the plugin (thousands of files);
 * a shared `opcache.file_cache` turns that into a load of precompiled scripts. The setting travels to the
 * children through the environment: `PHP_INI_SCAN_DIR` (the leading empty element keeps the default scan dir, so
 * extensions still load) points at a directory holding the ini, and Psalm's restarter copies every scanned ini
 * into the ini of the process it restarts into.
 *
 * The directory is fresh per run and never reused: Psalm's restarter forces `opcache.validate_timestamps=0`, so
 * a cache outliving a run would keep serving the plugin code from before the next edit. Paratest loads this file
 * in its main process before it spawns workers, and the workers inherit the env, so one run shares one directory.
 * The run removes its directory on exit; those left by crashed or killed runs are removed once their owning pid is gone.
 *
 * Opt out with PSALM_LARAVEL_TEST_NO_OPCACHE_FILE_CACHE=1 (it is also off without ext-posix, e.g. on Windows).
 */
(static function (): void {
    if (
        \getenv('PSALM_LARAVEL_TEST_OPCACHE_DIR') !== false
        || \getenv('PSALM_LARAVEL_TEST_NO_OPCACHE_FILE_CACHE') !== false
        || !\function_exists('posix_kill')
    ) {
        return;
    }

    $filesystem = new \Illuminate\Filesystem\Filesystem();
    $prefix = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-opcache-';

    foreach (\glob($prefix . '*', \GLOB_ONLYDIR) ?: [] as $stale) {
        $pid = (int) \substr($stale, \strlen($prefix));

        // EPERM means the pid exists but belongs to another user: still alive.
        if ($pid > 0 && !\posix_kill($pid, 0) && \posix_get_last_error() !== 1) {
            $filesystem->deleteDirectory($stale);
        }
    }

    // A reused pid may have left a directory of the same name behind: never inherit its cache.
    $directory = $prefix . \getmypid();
    $filesystem->deleteDirectory($directory);
    \mkdir($directory, 0700, true);
    \file_put_contents($directory . '/file-cache.ini', 'opcache.file_cache="' . $directory . "\"\n");

    // Only this root process registers the hook (workers return early above), so the cache outlives every worker.
    \register_shutdown_function(static fn() => $filesystem->deleteDirectory($directory));

    $env = [
        'PSALM_LARAVEL_TEST_OPCACHE_DIR' => $directory,
        'PHP_INI_SCAN_DIR' => \getenv('PHP_INI_SCAN_DIR') . \PATH_SEPARATOR . $directory,
    ];

    // Symfony Process, which the forking tests use, copies only what `$_SERVER`/`$_ENV` hold: `putenv()` alone is lost.
    foreach ($env as $name => $value) {
        \putenv($name . '=' . $value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
})();

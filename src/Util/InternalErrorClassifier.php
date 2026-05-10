<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

/**
 * Classifies a {@see \Throwable} raised during plugin initialisation by walking
 * its stack trace and emitting a hint that points at the most likely fix site:
 * the user's application, the plugin's own bridge code, the Laravel framework,
 * Orchestra Testbench, or another vendor package.
 *
 * The hint is best-effort. When no frame matches a known category, we return
 * null and the caller falls back to the generic "report it upstream" link from
 * {@see InternalErrorReporter}.
 *
 * Inspired by Larastan's `BootstrapErrorHandler` (3.9+); see issue #897.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class InternalErrorClassifier
{
    /** @psalm-mutation-free */
    public static function hint(\Throwable $throwable): ?string
    {
        foreach (self::frameFiles($throwable) as $file) {
            $hint = self::hintForFile($file);
            if ($hint !== null) {
                return $hint;
            }
        }

        return null;
    }

    /**
     * Classify a single frame's file path. Returns null when the frame is
     * plugin-internal error machinery (so the caller skips it and inspects the
     * next frame).
     *
     * Public for unit testing — production callers should use {@see hint()}.
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function hintForFile(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        // Normalise Windows separators so substring matches work the same everywhere
        $normalized = \str_replace('\\', '/', $file);

        // Skip plugin-internal error reporting frames — they appear on every
        // failure path and would otherwise classify every error as "plugin bug"
        if (
            \str_contains($normalized, '/Util/InternalErrorReporter.php')
            || \str_contains($normalized, '/Util/InternalErrorClassifier.php')
        ) {
            return null;
        }

        if (\str_contains($normalized, '/vendor/orchestra/testbench')) {
            return 'The failure originated inside Orchestra Testbench. The plugin falls back to Testbench when '
                . 'ApplicationProvider cannot boot your application directly. Check that your project exposes a '
                . 'runnable bootstrap/app.php or that your Laravel version is supported by the Testbench pin.';
        }

        if (
            \str_contains($normalized, '/vendor/laravel/framework')
            || \str_contains($normalized, '/vendor/illuminate/')
        ) {
            return 'The failure originated inside Laravel framework code. Try reproducing it with a plain '
                . '`php artisan` command. If it fails there too, the bug is upstream rather than in the plugin.';
        }

        if (
            \str_contains($normalized, '/psalm-plugin-laravel/src/')
            || \str_contains($normalized, '/vendor/psalm/plugin-laravel/src/')
        ) {
            return 'The failure originated inside the Laravel plugin itself. This is likely a plugin bug. '
                . 'Please attach the full stack trace to the report linked below.';
        }

        if (\str_contains($normalized, '/vendor/')) {
            return "The failure originated inside a third-party package ({$file}). Check whether the package "
                . 'supports the Laravel and PHP versions you are running.';
        }

        // Anything else is presumed to be user application code (bootstrap/app.php,
        // app/Providers/*, custom bindings, etc.). The plugin boots the user's real
        // Laravel kernel, so service-provider crashes surface here.
        return "The failure originated inside your application code ({$file}). The plugin boots your real "
            . 'Laravel kernel, so service-provider or bootstrap errors surface during plugin initialisation.';
    }

    /**
     * Collect the throw site followed by every frame's file from the trace, in order.
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private static function frameFiles(\Throwable $throwable): array
    {
        $files = [];

        $throwSite = $throwable->getFile();
        if ($throwSite !== '') {
            $files[] = $throwSite;
        }

        foreach ($throwable->getTrace() as $frame) {
            if (isset($frame['file'])) {
                $files[] = $frame['file'];
            }
        }

        return $files;
    }
}

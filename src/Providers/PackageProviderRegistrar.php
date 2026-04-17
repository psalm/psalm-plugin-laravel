<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Foundation\Application;
use Psalm\Progress\Progress;

/**
 * Registers Laravel service providers declared by the analysed project
 * (and its installed dependencies) into the plugin's booted application.
 *
 * The plugin boots an internal Orchestra Testbench app to answer container
 * lookups (`app()`, `resolve()`, `make()`). Testbench only loads Laravel
 * framework providers — it is ignorant of the analysed package's own
 * providers. Without those providers, any string alias the package binds
 * (e.g. `app('datatables.request')`) throws `BindingResolutionException`
 * inside {@see \Psalm\LaravelPlugin\Util\ContainerResolver}, and the call
 * site falls back to `mixed`.
 *
 * Sources consulted:
 *
 * - `composer.json` at the project root — `extra.laravel.providers`. Covers
 *   the analysed package's own providers (typical case when the analysed
 *   project is itself a Laravel package, e.g. `yajra/laravel-datatables`).
 * - `composer.lock` at the project root — `packages[*].extra.laravel.providers`
 *   and `packages-dev[*].extra.laravel.providers`. Covers providers declared
 *   by installed dependencies, mirroring what Laravel's package-discovery
 *   manifest would build from `vendor/composer/installed.json`.
 *
 * Respects the same `extra.laravel.dont-discover` opt-out that Laravel's own
 * {@see \Illuminate\Foundation\PackageManifest} honors. Package names listed
 * under the root `composer.json`'s `extra.laravel.dont-discover` are skipped;
 * a literal `"*"` disables discovery entirely.
 *
 * Each provider is registered under a `try/catch`. Providers that fail to
 * register (missing optional deps, unresolvable constructor bindings,
 * autoload errors) are reported as warnings but don't stop the run — one
 * broken provider must not disable the plugin for the whole project. This
 * mirrors the defensive pattern used by {@see FacadeMapProvider::init()}.
 *
 * Idempotency: Laravel's {@see Application::register()} returns early when
 * a provider class is already registered (unless `$force = true`). When the
 * analysed project has a `bootstrap/app.php`, Laravel's bootstrap has
 * already registered the configured providers, so our calls become no-ops.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/766
 *
 * @internal
 */
final class PackageProviderRegistrar
{
    public static function register(Application $app, string $projectRoot, Progress $progress): void
    {
        $providerClasses = self::discoverProviderClasses($projectRoot, $progress);

        // Breadcrumb for users trying to figure out what the registrar did — invisible
        // at default verbosity but surfaces under `--debug`. Warnings below cover the
        // "something went wrong" case; this covers the "was anything attempted?" case.
        $progress->debug(
            \sprintf(
                "Laravel plugin: discovered %d package service provider(s) to register from '%s'\n",
                \count($providerClasses),
                $projectRoot,
            ),
        );

        foreach ($providerClasses as $providerClass) {
            try {
                $app->register($providerClass);
            } catch (\Throwable $e) {
                // One bad provider shouldn't disable static analysis for the whole project.
                // Warning (not debug) so users actively debugging a missing binding can see
                // the provider class name and reason — the typical failure modes are
                // actionable: missing optional deps, autoload errors, eager bindings that
                // throw. If this becomes noisy for some projects we can dedupe per class.
                $progress->warning(
                    "Laravel plugin: skipped service provider '{$providerClass}': {$e->getMessage()}",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function discoverProviderClasses(string $projectRoot, Progress $progress): array
    {
        $composerJsonPath = $projectRoot . '/composer.json';
        $composerJson = \is_file($composerJsonPath)
            ? self::readJsonFile($composerJsonPath, $progress)
            : null;

        // Laravel's PackageManifest reads `extra.laravel.dont-discover` from the root
        // composer.json only (never from installed packages). It's a package-name skip
        // list; "*" means "skip everything".
        $dontDiscover = self::readExtraLaravelStringList($composerJson, 'dont-discover');

        if (\in_array('*', $dontDiscover, true)) {
            return [];
        }

        $providers = self::readExtraLaravelStringList($composerJson, 'providers');

        // composer.lock preserves each installed package's composer.json data under
        // `packages[*]` (runtime deps) and `packages-dev[*]` (dev deps). This matches
        // what Laravel's PackageManifest builds from `vendor/composer/installed.json`,
        // but composer.lock is always at a predictable location (the project root).
        $composerLockPath = $projectRoot . '/composer.lock';
        if (\is_file($composerLockPath)) {
            $composerLock = self::readJsonFile($composerLockPath, $progress);
            if ($composerLock !== null) {
                foreach (['packages', 'packages-dev'] as $section) {
                    /** @var mixed $packages */
                    $packages = $composerLock[$section] ?? null;

                    if (!\is_array($packages)) {
                        continue;
                    }

                    /** @var mixed $package */
                    foreach ($packages as $package) {
                        if (!\is_array($package)) {
                            continue;
                        }

                        /** @var mixed $packageName */
                        $packageName = $package['name'] ?? null;
                        if (\is_string($packageName) && \in_array($packageName, $dontDiscover, true)) {
                            // Package explicitly opted out by the root composer.json.
                            continue;
                        }

                        foreach (self::readExtraLaravelStringList($package, 'providers') as $provider) {
                            $providers[] = $provider;
                        }
                    }
                }
            }
        }

        return \array_values(\array_unique($providers));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function readJsonFile(string $path, Progress $progress): ?array
    {
        $content = \file_get_contents($path);

        if ($content === false) {
            $progress->warning("Laravel plugin: could not read '{$path}'");
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $progress->warning("Laravel plugin: could not parse '{$path}': {$e->getMessage()}");
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Read a non-empty string list from `extra.laravel.$key`.
     *
     * Used for both `extra.laravel.providers` (composer.json-shaped package data,
     * one list per source) and the root composer.json's `extra.laravel.dont-discover`
     * skip list. Walks the path defensively — each level must be an array and each
     * leaf must be a non-empty string, otherwise the value is dropped. composer.json
     * is user-authored, so a malformed manifest must not break analysis.
     *
     * @param array<array-key, mixed>|null $data
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function readExtraLaravelStringList(?array $data, string $key): array
    {
        if ($data === null) {
            return [];
        }

        /** @var mixed $extra */
        $extra = $data['extra'] ?? null;
        if (!\is_array($extra)) {
            return [];
        }

        /** @var mixed $laravel */
        $laravel = $extra['laravel'] ?? null;
        if (!\is_array($laravel)) {
            return [];
        }

        /** @var mixed $values */
        $values = $laravel[$key] ?? null;
        if (!\is_array($values)) {
            return [];
        }

        $result = [];

        /** @var mixed $value */
        foreach ($values as $value) {
            if (\is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }
}

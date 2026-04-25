<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Foundation\Application;
use Psalm\Progress\Progress;

/**
 * Registers service providers found in the analysed project's composer.json
 * and composer.lock into the Testbench-booted app.
 *
 * When analysing a Laravel *package* (no bootstrap/app.php), the plugin uses
 * Orchestra Testbench which only boots the framework's own providers. Any
 * string alias the package registers (e.g. `$this->app->singleton('my.alias', …)`)
 * is therefore unknown to the app, causing `app('my.alias')` to fall back to
 * `mixed` in ContainerResolver::resolveFromApplicationContainer().
 *
 * This class reads `extra.laravel.providers` from the analysed project's
 * composer.json and its composer.lock, then registers each discovered provider
 * into the already-booted app.  Registration is idempotent in Laravel
 * (Application::register() returns early if the provider is already loaded),
 * so it is safe to call this even for full-application analysed projects.
 *
 * @internal
 */
final class PackageProviderRegistrar
{
    /**
     * Discover and register providers from the analysed project.
     *
     * @param non-empty-string $projectRoot
     */
    public static function register(Application $app, string $projectRoot, Progress $progress): void
    {
        $providers = self::discoverProviders($projectRoot);

        foreach ($providers as $providerClass) {
            if (!\class_exists($providerClass)) {
                $progress->debug("Laravel plugin: PackageProviderRegistrar skipped {$providerClass}: class not found\n");
                continue;
            }

            try {
                $app->register($providerClass);
            } catch (\Throwable $e) {
                $progress->debug("Laravel plugin: PackageProviderRegistrar failed to register {$providerClass}: {$e->getMessage()}\n");
            }
        }
    }

    /**
     * Collect all service provider class names from the project's
     * composer.json (`extra.laravel.providers`) and the providers listed in
     * composer.lock for every non-excluded package dependency.
     *
     * Respects the `extra.laravel.dont-discover` list in composer.json
     * (same semantics as Laravel's own package discovery).
     *
     * @param non-empty-string $projectRoot
     * @return list<string>
     */
    public static function discoverProviders(string $projectRoot): array
    {
        /** @var array<string, true> $dontDiscover */
        $dontDiscover = [];
        /** @var list<string> $providers */
        $providers = [];

        $composerJsonPath = $projectRoot . '/composer.json';

        if (\file_exists($composerJsonPath)) {
            /** @var array{extra?: array{laravel?: array{providers?: list<string>, 'dont-discover'?: list<string>}}} $composerJson */
            $composerJson = \json_decode((string) \file_get_contents($composerJsonPath), true) ?? [];
            $laravelExtra = $composerJson['extra']['laravel'] ?? [];

            foreach ($laravelExtra['dont-discover'] ?? [] as $excluded) {
                $dontDiscover[$excluded] = true;
            }

            foreach ($laravelExtra['providers'] ?? [] as $provider) {
                $providers[] = $provider;
            }
        }

        // Wildcard dont-discover ('*') skips all package-lock providers.
        if (isset($dontDiscover['*'])) {
            return \array_values(\array_unique($providers));
        }

        $composerLockPath = $projectRoot . '/composer.lock';

        if (\file_exists($composerLockPath)) {
            /**
             * @var array{packages?: list<array{name: string, extra?: array{laravel?: array{providers?: list<string>}}}>} $composerLock
             */
            $composerLock = \json_decode((string) \file_get_contents($composerLockPath), true) ?? [];

            foreach ($composerLock['packages'] ?? [] as $package) {
                $packageName = $package['name'] ?? '';

                if (isset($dontDiscover[$packageName])) {
                    continue;
                }

                foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                    $providers[] = $provider;
                }
            }
        }

        return \array_values(\array_unique($providers));
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;

/**
 * Collects runtime introspection data about the plugin's resolved state.
 *
 * Subclassable so unit tests can override {@see collect()} with a fixture
 * report without booting Laravel.
 *
 * @internal
 */
class Diagnostics
{
    private const PLUGIN_PACKAGE = 'psalm/plugin-laravel';

    public function collect(): Report
    {
        $bootError = null;

        try {
            ApplicationProvider::bootApp();
        } catch (\Throwable $throwable) {
            $bootError = $throwable->getMessage();
        }

        $hardFailures = [];
        if ($bootError !== null) {
            $hardFailures[] = "Application boot failed: {$bootError}";
        }

        return new Report(
            pluginVersion: $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            laravelVersion: \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            psalmVersion: $this->safePrettyVersion('vimeo/psalm'),
            phpVersion: \PHP_VERSION,
            bootMode: ApplicationProvider::getBootMode(),
            bootPath: ApplicationProvider::getBootPath(),
            bootError: $bootError,
            hardFailures: $hardFailures,
        );
    }

    private function safePrettyVersion(string $package): ?string
    {
        if (!InstalledVersions::isInstalled($package)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion($package);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}

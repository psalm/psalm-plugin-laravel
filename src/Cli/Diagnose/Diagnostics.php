<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;

/**
 * Collects runtime introspection data about the plugin's resolved state.
 *
 * Returns a plain associative array so the renderer stays decoupled from any
 * value-object shape.
 *
 * @internal
 *
 * @psalm-type VersionSection = array{
 *     plugin: ?string,
 *     laravel: ?string,
 *     psalm: ?string,
 *     php: string,
 * }
 * @psalm-type BootSection = array{
 *     mode: ?string,
 *     description: ?string,
 *     path: ?string,
 *     error: ?string,
 * }
 * @psalm-type Report = array{
 *     versions: VersionSection,
 *     boot: BootSection,
 *     hard_failures: list<string>,
 * }
 */
class Diagnostics
{
    private const PLUGIN_PACKAGE = 'psalm/plugin-laravel';

    /**
     * @return Report
     */
    public function collect(): array
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

        return [
            'versions' => [
                'plugin' => $this->safePrettyVersion(self::PLUGIN_PACKAGE),
                'laravel' => \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
                'psalm' => $this->safePrettyVersion('vimeo/psalm'),
                'php' => \PHP_VERSION,
            ],
            'boot' => [
                'mode' => ApplicationProvider::getBootMode(),
                'description' => ApplicationProvider::getBootDescription(),
                'path' => ApplicationProvider::getBootPath(),
                'error' => $bootError,
            ],
            'hard_failures' => $hardFailures,
        ];
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

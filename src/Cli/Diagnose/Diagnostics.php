<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Config\PluginOverrides;

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

    /**
     * @param list<string> $cliOptions `KEY=VALUE` tokens from `--plugin-option` / `--blade`, in command-line order.
     */
    public function collect(array $cliOptions = []): Report
    {
        $bootstrapErrors = [];

        // Read before the boot: Laravel's Dotenv loader can putenv() the project's `.env` entries, but the
        // plugin resolves its settings before the app boots and never sees them.
        $envOptions = \getenv(PluginOverrides::ENV_VAR);

        try {
            ApplicationProvider::bootApp();
        } catch (\Throwable $throwable) {
            $bootstrapErrors[] = $throwable->getMessage();
        }

        // Throws swallowed inside `doGetApp()` (e.g. `$consoleApp->bootstrap()`
        // failing on a bad `config/*.php`) never propagate to the catch above —
        // ApplicationProvider stashes them so diagnose can surface partial-boot state.
        $swallowed = ApplicationProvider::getBootstrapError();
        if ($swallowed instanceof \Throwable) {
            $bootstrapErrors[] = $swallowed->getMessage();
        }

        // A null bootMode means the boot pipeline never reached a resolution branch
        // (the try/catch above swallowed a hard throw). Treat that as a hard failure
        // so the CLI exits non-zero; partial-bootstrap warnings alone are informational.
        $hardFailures = [];
        if (ApplicationProvider::getBootMode() === null && $bootstrapErrors !== []) {
            $hardFailures[] = 'Application boot failed: ' . $bootstrapErrors[0];
        }

        $cwd = \getcwd();
        $projectRoot = \is_string($cwd) ? $cwd : null;

        // Invalid input is a hard failure with the message, not an exception that escapes as a stack trace.
        $psalmXml = null;
        $pluginSettings = [];

        try {
            $psalmXml = $projectRoot === null ? null : $this->readPsalmXml($projectRoot);
            $pluginSettings = PluginSettings::resolve($psalmXml, \is_string($envOptions) ? $envOptions : null, $cliOptions);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $hardFailures[] = 'Plugin settings: ' . $invalidArgumentException->getMessage();
        }

        [$analysisVersion, $analysisSource] = $this->resolveAnalysisPhpVersion($psalmXml);

        return new Report(
            pluginVersion: $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            psalmVersion: $this->safePrettyVersion('vimeo/psalm'),
            laravelVersion: \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            phpRuntimeVersion: \PHP_VERSION,
            phpAnalysisVersion: $analysisVersion,
            phpAnalysisSource: $analysisSource,
            bootMode: ApplicationProvider::getBootMode(),
            bootPath: ApplicationProvider::getBootPath(),
            bootstrapErrors: $bootstrapErrors,
            hardFailures: $hardFailures,
            loadedProviders: $this->collectLoadedProviders(),
            pluginSettings: $pluginSettings,
        );
    }

    /**
     * Service providers the booted kernel registered, sorted for stable output.
     *
     * Includes framework core providers plus anything package discovery (and, in
     * package-source boots, {@see ApplicationProvider::registerDiscoveredVendorProviders()})
     * registered. Returns an empty list when the app never resolved — `getApp()`
     * throws in that case, and a failed boot has no providers to report.
     *
     * @return list<string>
     */
    private function collectLoadedProviders(): array
    {
        try {
            $providers = \array_keys(ApplicationProvider::getApp()->getLoadedProviders());
        } catch (\Throwable) {
            return [];
        }

        \sort($providers);

        return $providers;
    }

    /**
     * Resolve the PHP version Psalm uses for analysis. Only `psalm.xml`'s
     * `phpVersion=` attribute is a concrete version; otherwise we fall back
     * to the runtime.
     *
     * @return array{string, 'runtime'|'psalm.xml'}
     */
    private function resolveAnalysisPhpVersion(?\SimpleXMLElement $psalmXml): array
    {
        $fromXml = (string) ($psalmXml['phpVersion'] ?? '');

        return $fromXml === '' ? [\PHP_VERSION, 'runtime'] : [$fromXml, 'psalm.xml'];
    }

    /**
     * Read `<projectRoot>/psalm.xml`, or null when there is none. We don't walk parent directories —
     * diagnose is intended for the project root.
     *
     * We parse it directly with SimpleXML instead of `Config::getConfigForPath()` because the latter
     * eagerly validates every entry in `$argv` as a filesystem path (see Psalm's
     * {@see \Psalm\Internal\CliUtils::getPathsToCheck()}) and `exit(1)`s on
     * `bin/psalm-laravel diagnose` — its Symfony bypass only spares the `psalm-plugin` binary.
     *
     * @throws \InvalidArgumentException When the file exists but is not well-formed XML.
     */
    private function readPsalmXml(string $projectRoot): ?\SimpleXMLElement
    {
        $path = $projectRoot . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $contents = \is_file($path) ? \file_get_contents($path) : false;
        if ($contents === false) {
            return null;
        }

        // Toggle libxml's internal error buffer so a malformed psalm.xml never
        // bubbles a warning to STDOUT and breaks the diagnose report layout.
        $previous = \libxml_use_internal_errors(true);
        // Psalm expands XIncludes (an include's fallback can hold plugin settings); relative hrefs resolve
        // against the working directory, which is `$projectRoot` here as it is for Psalm's own load.
        $dom = new \DOMDocument();
        $xml = null;

        if ($contents !== '' && $dom->loadXML($contents, \LIBXML_NONET)) {
            $dom->xinclude(\LIBXML_NOWARNING | \LIBXML_NONET);
            $xml = \simplexml_import_dom($dom);
        }

        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        return $xml instanceof \SimpleXMLElement ? $xml : throw new \InvalidArgumentException('psalm.xml is not well-formed XML.');
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

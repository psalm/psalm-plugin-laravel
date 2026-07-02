<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Config\ExperimentalFeature;
use Psalm\LaravelPlugin\Plugin;

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
        $bootstrapErrors = [];

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

        [$analysisVersion, $analysisSource] = $this->resolveAnalysisPhpVersion($projectRoot);

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
            experimentalFeaturesEnabled: $this->readEnabledExperimentalFeatures($projectRoot),
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
     * We parse `psalm.xml` directly with SimpleXML instead of
     * `Config::getConfigForPath()` because the latter eagerly validates every
     * entry in `$argv` as a filesystem path (see Psalm's
     * {@see \Psalm\Internal\CliUtils::getPathsToCheck()}) and `exit(1)`s on
     * `bin/psalm-laravel diagnose` — its Symfony bypass only spares the `psalm-plugin` binary.
     *
     * @return array{string, 'runtime'|'psalm.xml'}
     */
    private function resolveAnalysisPhpVersion(?string $projectRoot): array
    {
        if ($projectRoot !== null) {
            $fromXml = $this->readPsalmXmlPhpVersion($projectRoot);
            if ($fromXml !== null) {
                return [$fromXml, 'psalm.xml'];
            }
        }

        return [\PHP_VERSION, 'runtime'];
    }

    /**
     * Read the `phpVersion` attribute from `<projectRoot>/psalm.xml`. We don't
     * walk parent directories — diagnose is intended for the project root.
     */
    private function readPsalmXmlPhpVersion(string $projectRoot): ?string
    {
        $xml = $this->loadPsalmXml($projectRoot);
        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $attr = $xml['phpVersion'] ?? null;
        if (!$attr instanceof \SimpleXMLElement) {
            return null;
        }

        $value = (string) $attr;
        return $value === '' ? null : $value;
    }

    /**
     * Feature values from every `<experimental>` entry enabled in the user's psalm.xml,
     * resolved against {@see ExperimentalFeature}. Unlike `PluginConfig::fromXml()`, this
     * never throws or emits a deprecation notice for an unrecognized/graduated/withdrawn
     * name — diagnose describes what is currently live, not every mistake in psalm.xml; a
     * real Psalm run is what surfaces a config error. `all="true"` resolves to every case
     * regardless of `<feature>` children.
     *
     * @return list<non-empty-string>
     */
    private function readEnabledExperimentalFeatures(?string $projectRoot): array
    {
        if ($projectRoot === null) {
            return [];
        }

        $xml = $this->loadPsalmXml($projectRoot);
        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }

        $pluginClass = $this->findPluginClassElement($xml);
        if (!$pluginClass instanceof \SimpleXMLElement || !isset($pluginClass->experimental)) {
            return [];
        }

        /** @psalm-var \SimpleXMLElement $experimental */
        $experimental = $pluginClass->experimental;

        if ((string) ($experimental['all'] ?? 'false') === 'true') {
            return \array_map(static fn(ExperimentalFeature $case): string => $case->value, ExperimentalFeature::cases());
        }

        $enabled = [];

        /** @psalm-var iterable<\SimpleXMLElement> $children */
        $children = $experimental->feature;

        foreach ($children as $node) {
            $feature = ExperimentalFeature::tryFrom((string) ($node['name'] ?? ''));

            if ($feature !== null && !\in_array($feature->value, $enabled, true)) {
                $enabled[] = $feature->value;
            }
        }

        return $enabled;
    }

    /**
     * Find this plugin's `<pluginClass>` among possibly several `<plugins>` entries.
     *
     * The `isset()` guard matters, not just an `instanceof` check: chaining a second
     * dynamic-property access off an already-absent SimpleXML proxy (no `<plugins>` at all,
     * e.g. this repo's own self-analysis psalm.xml) degrades `->pluginClass` to real `null`
     * instead of another empty-but-iterable proxy — foreach() on that warns at runtime even
     * though Psalm's stub types the chain as non-nullable. Off a genuinely-present (if
     * childless) `<plugins>`, the same chain is always safely iterable.
     */
    private function findPluginClassElement(\SimpleXMLElement $xml): ?\SimpleXMLElement
    {
        if (!isset($xml->plugins)) {
            return null;
        }

        $plugins = $xml->plugins;

        /** @psalm-var iterable<\SimpleXMLElement> $pluginClasses */
        $pluginClasses = $plugins->pluginClass;

        foreach ($pluginClasses as $node) {
            $class = \ltrim((string) ($node['class'] ?? ''), '\\');

            if ($class === \ltrim(Plugin::class, '\\')) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Load `<projectRoot>/psalm.xml` as a SimpleXMLElement, or null if it does not exist or is
     * malformed. Shared by every diagnose fact read directly off the user's psalm.xml (PHP
     * version, active experimental features) instead of `Config::getConfigForPath()` — see
     * {@see resolveAnalysisPhpVersion()} for why.
     */
    private function loadPsalmXml(string $projectRoot): ?\SimpleXMLElement
    {
        $path = $projectRoot . \DIRECTORY_SEPARATOR . 'psalm.xml';
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        // Toggle libxml's internal error buffer so a malformed psalm.xml never
        // bubbles a warning to STDOUT and breaks the diagnose report layout.
        $previous = \libxml_use_internal_errors(true);
        $xml = \simplexml_load_string($contents);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        return $xml instanceof \SimpleXMLElement ? $xml : null;
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

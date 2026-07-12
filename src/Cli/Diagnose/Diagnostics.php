<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Config\PluginConfig;
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

        $projectRoot = $this->projectRoot();

        [$analysisVersion, $analysisSource] = $this->resolveAnalysisPhpVersion($projectRoot);

        return new Report(
            pluginVersion: $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            psalmVersion: $this->safePrettyVersion('vimeo/psalm'),
            laravelVersion: \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            phpRuntimeVersion: \PHP_VERSION,
            phpAnalysisVersion: $analysisVersion,
            phpAnalysisSource: $analysisSource,
            experimentalIssueEnforcement: $this->resolveExperimentalIssueEnforcement($projectRoot),
            bootMode: ApplicationProvider::getBootMode(),
            bootPath: ApplicationProvider::getBootPath(),
            bootstrapErrors: $bootstrapErrors,
            hardFailures: $hardFailures,
            loadedProviders: $this->collectLoadedProviders(),
        );
    }

    protected function projectRoot(): ?string
    {
        $cwd = \getcwd();

        return \is_string($cwd) ? $cwd : null;
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
     * to the runtime. The same config lookup is also used for the experimental
     * issue-enforcement state shown by diagnose.
     *
     * We parse `psalm.xml` directly with SimpleXML instead of
     * `Config::getConfigForPath()` because the latter eagerly validates every
     * entry in `$argv` as a filesystem path (see Psalm's
     * {@see \Psalm\Internal\CliUtils::getPathsToCheck()}) and `exit(1)`s on
     * `bin/psalm-laravel diagnose` — its Symfony bypass only spares the `psalm-plugin` binary.
     *
     * @return array{string, 'runtime'|'psalm.xml'|'psalm.xml.dist'}
     */
    private function resolveAnalysisPhpVersion(?string $projectRoot): array
    {
        if ($projectRoot !== null) {
            $fromXml = $this->readPsalmXmlPhpVersion($projectRoot);
            if ($fromXml !== null) {
                return $fromXml;
            }
        }

        return [\PHP_VERSION, 'runtime'];
    }

    /**
     * Read the `phpVersion` attribute from the project's psalm.xml, falling
     * back to psalm.xml.dist. We don't walk parent directories — diagnose is
     * intended for the project root.
     *
     * @return array{string, 'psalm.xml'|'psalm.xml.dist'}|null
     */
    private function readPsalmXmlPhpVersion(string $projectRoot): ?array
    {
        [$xml, $source] = $this->readPsalmXml($projectRoot) ?? [null, null];
        if (!$xml instanceof \SimpleXMLElement || !\is_string($source)) {
            return null;
        }

        $attr = $xml['phpVersion'] ?? null;
        if (!$attr instanceof \SimpleXMLElement) {
            return null;
        }

        $value = (string) $attr;
        return $value === '' ? null : [$value, $source];
    }

    protected function resolveExperimentalIssueEnforcement(?string $projectRoot): bool
    {
        if ($projectRoot === null) {
            return false;
        }

        [$xml] = $this->readPsalmXml($projectRoot) ?? [null];
        if (!$xml instanceof \SimpleXMLElement) {
            return false;
        }

        $plugins = $xml->plugins;
        if (!$plugins instanceof \SimpleXMLElement) {
            return false;
        }

        $pluginClasses = $plugins->pluginClass;
        if (!$pluginClasses instanceof \SimpleXMLElement) {
            return false;
        }

        foreach ($pluginClasses as $pluginClass) {
            if (!$this->isPluginClass((string) $pluginClass['class'])) {
                continue;
            }

            try {
                return PluginConfig::fromXml($pluginClass->children())->experimental;
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return false;
    }

    /** @psalm-pure */
    private function isPluginClass(string $class): bool
    {
        return \strtolower(\ltrim($class, '\\')) === \strtolower(Plugin::class);
    }

    /**
     * @return array{\SimpleXMLElement, 'psalm.xml'|'psalm.xml.dist'}|null
     */
    private function readPsalmXml(string $projectRoot): ?array
    {
        foreach (['psalm.xml', 'psalm.xml.dist'] as $source) {
            $path = $projectRoot . \DIRECTORY_SEPARATOR . $source;
            if (!\is_file($path)) {
                continue;
            }

            $contents = \file_get_contents($path);
            if ($contents === false) {
                return null;
            }

            // Toggle libxml's internal error buffer so a malformed config never
            // bubbles a warning to STDOUT and breaks the diagnose report layout.
            $previous = \libxml_use_internal_errors(true);
            $xml = \simplexml_load_string($contents);
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);

            if ($xml instanceof \SimpleXMLElement) {
                return [$xml, $source];
            }

            // Psalm selects the first existing configuration filename. A malformed
            // psalm.xml must not make diagnose silently read psalm.xml.dist instead.
            return null;
        }

        return null;
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

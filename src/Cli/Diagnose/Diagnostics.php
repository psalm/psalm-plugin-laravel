<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\Config;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\ShadowRegistrar;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Config\PluginOverrides;
use Psalm\LaravelPlugin\Plugin;
use Psalm\Progress\VoidProgress;

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

    /** Psalm's own `Config::DEFAULT_FILE_NAMES` (private there), in its lookup order. */
    private const CONFIG_FILE_NAMES = ['psalm.xml', 'psalm.xml.dist', 'psalm.dist.xml'];

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
            blade: $this->bladeStatus($projectRoot),
        );
    }

    /**
     * Runs the same Blade boot a Psalm run does, against a registrar that only counts, so the
     * reported reason is the one that run would print. It compiles into the configured cache
     * directory like that run, which only warms the cache the next run reads.
     *
     * @return string `off`, `on (N templates, cache <dir>)`, or `disabled: <reason>`
     */
    public function bladeStatus(?string $projectRoot): string
    {
        $psalmXml = $projectRoot === null ? null : $this->locatePsalmXml($projectRoot);
        $pluginXml = $this->readPluginXml($psalmXml);

        if ($pluginXml === false) {
            return 'off';
        }

        try {
            $overrides = PluginOverrides::parse((string) \getenv(PluginOverrides::ENV_VAR));
            $config = PluginConfig::fromXml($pluginXml, $overrides);

            if (!$config->bladeEnabled) {
                return 'off';
            }

            // The default cache directory derives from Psalm's own, which needs a loaded Psalm config.
            if ($psalmXml !== null && $projectRoot !== null && PluginConfig::configuredBladeCacheDir($pluginXml) === null) {
                $this->loadPsalmConfig($psalmXml, $projectRoot);
                $config = PluginConfig::fromXml($pluginXml, $overrides);
            }

            $registrar = new class implements ShadowRegistrar {
                public int $shadows = 0;

                /** @psalm-pure */
                #[\Override]
                public function markTemplatesReportable(array $templatePaths): bool
                {
                    return true;
                }

                /** @psalm-capabilities read-props|write-this-props|write-refs */
                #[\Override]
                public function registerShadowsForAnalysis(array $shadowPaths): void
                {
                    $this->shadows = \count($shadowPaths);
                }

                /** @psalm-capabilities read-props */
                #[\Override]
                public function queueClassLikesForScanning(array $classNames): void {}

                /** @psalm-capabilities read-props */
                #[\Override]
                public function queueResolvableClassLikesForScanning(array $candidates): void {}

                /** @psalm-capabilities read-props */
                #[\Override]
                public function queueFilesForScanning(array $paths): void {}

                /** @psalm-capabilities read-props */
                #[\Override]
                public function reportCompileFailure(string $templatePath, string $source, string $message): void {}
            };

            // failOnInternalError keeps the disable warning off STDERR: this report states it instead.
            $bootstrapper = new BladeBootstrapper(ApplicationProvider::getApp(), $registrar, new VoidProgress(), $config->bladeCacheDir, failOnInternalError: true);
            $bootstrapper->boot();
        } catch (\Throwable $throwable) {
            return 'disabled: ' . $throwable->getMessage();
        }

        $cause = $bootstrapper->disabledCause();

        if ($cause !== null) {
            return 'disabled: ' . $cause;
        }

        return \sprintf('on (%d template%s, cache %s)', $registrar->shadows, $registrar->shadows === 1 ? '' : 's', $config->bladeCacheDir);
    }

    /**
     * Loading reads the constant Psalm's CLI defines, and `exit(1)`s on any argv token that is not an
     * existing path (see {@see resolveAnalysisPhpVersion()}), so argv is hidden for the duration.
     * Not private: a test stops here, since loading plants Psalm's Config singleton.
     */
    protected function loadPsalmConfig(string $psalmXml, string $projectRoot): void
    {
        global $argv;

        if (!\defined('PSALM_VERSION')) {
            \define('PSALM_VERSION', $this->safePrettyVersion('vimeo/psalm') ?? 'unknown');
        }

        $saved = $argv;
        $argv = [];

        try {
            Config::loadFromXMLFile($psalmXml, $projectRoot);
        } finally {
            $argv = $saved;
        }
    }

    /**
     * This plugin's `<pluginClass>` children, as Psalm hands them to the plugin.
     *
     * @return \SimpleXMLElement|null|false null when the plugin is registered without settings,
     *                                      false when it is not registered at all
     */
    private function readPluginXml(?string $psalmXml): \SimpleXMLElement|false|null
    {
        $xml = $psalmXml === null ? null : $this->loadPsalmXml($psalmXml);

        foreach ($xml?->plugins->pluginClass ?? [] as $plugin) {
            if (\strcasecmp(\ltrim((string) $plugin['class'], '\\'), Plugin::class) === 0) {
                return $plugin->count() > 0 ? $plugin->children() : null;
            }
        }

        return false;
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
     * Read the `phpVersion` attribute from the project root's Psalm config. We don't
     * walk parent directories — diagnose is intended for the project root.
     */
    private function readPsalmXmlPhpVersion(string $projectRoot): ?string
    {
        $path = $this->locatePsalmXml($projectRoot);
        $xml = $path === null ? null : $this->loadPsalmXml($path);

        $attr = $xml['phpVersion'] ?? null;
        if (!$attr instanceof \SimpleXMLElement) {
            return null;
        }

        $value = (string) $attr;
        return $value === '' ? null : $value;
    }

    private function locatePsalmXml(string $projectRoot): ?string
    {
        foreach (self::CONFIG_FILE_NAMES as $fileName) {
            $path = $projectRoot . \DIRECTORY_SEPARATOR . $fileName;

            if (\is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Expands `<xi:include>` relative to the file, as Psalm does before it reads the config. */
    private function loadPsalmXml(string $path): ?\SimpleXMLElement
    {
        // Toggle libxml's internal error buffer so a malformed psalm.xml never
        // bubbles a warning to STDOUT and breaks the diagnose report layout.
        $previous = \libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $xml = null;

        if ($dom->load($path, \LIBXML_NONET)) {
            // Returns false for a file with nothing to include too, so, like Psalm, ignore the result.
            $dom->xinclude(\LIBXML_NOWARNING | \LIBXML_NONET);
            $xml = \simplexml_import_dom($dom);
        }

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

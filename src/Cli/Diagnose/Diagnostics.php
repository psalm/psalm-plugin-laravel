<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;

/**
 * Collects runtime introspection data about the plugin's resolved state.
 *
 * Subclassable so unit tests can override {@see collect()} with a fixture
 * report without booting Laravel.
 *
 * @internal
 *
 * @psalm-type ComposerJson = array{
 *     require?: array{php?: string},
 *     config?: array{'vendor-dir'?: string},
 * }
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
        $composerJson = $projectRoot !== null ? $this->readComposerJson($projectRoot) : null;
        $vendorDir = $this->resolveComposerVendorDir($composerJson);

        [$analysisVersion, $analysisSource] = $this->resolveAnalysisPhpVersion($projectRoot);

        return new Report(
            pluginVersion: $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            pluginInstallPath: $this->safeInstallPath(self::PLUGIN_PACKAGE),
            psalmVersion: $this->safePrettyVersion('vimeo/psalm'),
            laravelVersion: \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            osFamily: \PHP_OS_FAMILY,
            osVersion: \trim(\php_uname('s') . ' ' . \php_uname('r')),
            phpRuntimeVersion: \PHP_VERSION,
            phpBinaryPath: \PHP_BINARY,
            phpRequiredVersion: $this->readComposerRequirePhp($composerJson),
            phpAnalysisVersion: $analysisVersion,
            phpAnalysisSource: $analysisSource,
            composerVendorDir: $vendorDir,
            psalmBinExists: $projectRoot !== null && \is_file($this->binPath($projectRoot, $vendorDir, 'psalm')),
            psalmLaravelBinExists: $projectRoot !== null && \is_file($this->binPath($projectRoot, $vendorDir, 'psalm-laravel')),
            psalmConfigPath: $projectRoot !== null ? $this->detectPsalmConfigPath($projectRoot) : null,
            bootMode: ApplicationProvider::getBootMode(),
            bootPath: ApplicationProvider::getBootPath(),
            bootstrapErrors: $bootstrapErrors,
            hardFailures: $hardFailures,
            loadedProviders: $this->collectLoadedProviders(),
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
     * to the runtime. We deliberately do NOT consume `composer.json`'s
     * `require.php` here — that's a constraint (`^8.2`), not a resolved
     * version, and surfacing it under "Analysis" would mislead. The
     * constraint is reported separately under `phpRequiredVersion`.
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
     * Decode `<projectRoot>/composer.json` once so both the PHP constraint and
     * the vendor-dir override can be read from a single parse. Returns null on
     * any read/decode failure so callers can treat the project as if
     * composer.json weren't there.
     *
     * @return ComposerJson|null
     */
    private function readComposerJson(string $projectRoot): ?array
    {
        $path = $projectRoot . \DIRECTORY_SEPARATOR . 'composer.json';
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        /** @psalm-var array{require?: array{php?: string}, config?: array{'vendor-dir'?: string}}|null $decoded */
        $decoded = \json_decode($contents, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Return the raw `require.php` constraint string from `composer.json`
     * (e.g. `^8.2`). We don't resolve to a single minor like Psalm does — the
     * raw constraint is more informative for diagnose, and resolving it
     * requires shipping a per-release PHP version table.
     *
     * @param ComposerJson|null $composerJson
     * @psalm-pure
     */
    private function readComposerRequirePhp(?array $composerJson): ?string
    {
        return $composerJson['require']['php'] ?? null;
    }

    /**
     * Read composer's relocated vendor directory if configured, else 'vendor'.
     * Mirrors InitCommand::resolveVendorDir()'s normalisation.
     *
     * @param ComposerJson|null $composerJson
     * @psalm-pure
     */
    private function resolveComposerVendorDir(?array $composerJson): string
    {
        $configured = $composerJson['config']['vendor-dir'] ?? null;
        if ($configured === null || $configured === '') {
            return 'vendor';
        }

        $normalised = \rtrim(\preg_replace('#^\./#', '', $configured) ?? $configured, '/');
        return $normalised === '' ? 'vendor' : $normalised;
    }

    /** @psalm-pure */
    private function binPath(string $projectRoot, string $vendorDir, string $bin): string
    {
        return $projectRoot . \DIRECTORY_SEPARATOR . $vendorDir . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . $bin;
    }

    /**
     * First existing Psalm config path under $projectRoot, following Psalm's
     * own precedence (psalm.xml beats psalm.xml.dist). Null if neither exists.
     */
    private function detectPsalmConfigPath(string $projectRoot): ?string
    {
        foreach (['psalm.xml', 'psalm.xml.dist'] as $name) {
            $candidate = $projectRoot . \DIRECTORY_SEPARATOR . $name;
            if (\is_file($candidate)) {
                return $candidate;
            }
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

    private function safeInstallPath(string $package): ?string
    {
        if (!InstalledVersions::isInstalled($package)) {
            return null;
        }

        try {
            return InstalledVersions::getInstallPath($package);
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}

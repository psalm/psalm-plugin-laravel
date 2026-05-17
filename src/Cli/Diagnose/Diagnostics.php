<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
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

        $rootComposer = $this->readRootComposerJson();
        $phpRequiredConstraint = $this->readNestedString($rootComposer, ['require', 'php']);
        $platformPhp = $this->readNestedString($rootComposer, ['config', 'platform', 'php']);

        return new Report(
            pluginVersion: $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            laravelVersion: \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            psalmVersion: $this->safePrettyVersion('vimeo/psalm'),
            phpRuntimeVersion: \PHP_VERSION,
            phpRequiredVersion: $phpRequiredConstraint !== null ? $this->formatPhpRequiredRange($phpRequiredConstraint) : null,
            phpAnalysisVersion: $platformPhp ?? \PHP_VERSION,
            phpAnalysisSource: $platformPhp !== null ? 'config.platform.php' : 'runtime',
            bootMode: ApplicationProvider::getBootMode(),
            bootPath: ApplicationProvider::getBootPath(),
            bootstrapErrors: $bootstrapErrors,
            hardFailures: $hardFailures,
        );
    }

    /**
     * Resolve a Composer `require.php` constraint to a human-readable
     * `min-max` range using composer/semver bounds.
     *
     * Unlike phpstan we don't cap the upper bound at "max known PHP minor"
     * (e.g. `8.5.99`) — that requires shipping a per-release constants table.
     * We surface the exclusive upper bound as-is (e.g. `^8.2` → `8.2.0-9.0.0`).
     *
     * Returns the original constraint string if parsing fails.
     */
    private function formatPhpRequiredRange(string $constraint): string
    {
        try {
            $parsed = (new VersionParser())->parseConstraints($constraint);
        } catch (\UnexpectedValueException) {
            return $constraint;
        }

        $lower = $parsed->getLowerBound();
        $upper = $parsed->getUpperBound();

        $minStr = $lower->isZero() ? null : $this->trimSemverVersion($lower->getVersion());
        $maxStr = $upper->isPositiveInfinity() ? null : $this->trimSemverVersion($upper->getVersion());

        if ($minStr !== null && $maxStr !== null) {
            return $minStr === $maxStr ? $minStr : "{$minStr}-{$maxStr}";
        }

        if ($minStr !== null) {
            return ">={$minStr}";
        }

        if ($maxStr !== null) {
            return "<{$maxStr}";
        }

        return $constraint;
    }

    /**
     * composer/semver bounds return 4-segment versions with `-dev` markers
     * (e.g. `8.2.0.0-dev`). Reduce to standard `major.minor.patch` for display.
     *
     * @psalm-pure
     */
    private function trimSemverVersion(string $version): string
    {
        $version = \preg_replace('/-dev$/', '', $version) ?? $version;
        $parts = \explode('.', $version);
        if (\count($parts) >= 4 && $parts[3] === '0') {
            $parts = \array_slice($parts, 0, 3);
        }

        return \implode('.', $parts);
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

    /**
     * Read the root project's composer.json (the one Composer treats as
     * the project under analysis). Returns [] on any failure — diagnose
     * must never crash on missing or malformed files.
     *
     * @return array<array-key, mixed>
     */
    private function readRootComposerJson(): array
    {
        try {
            /** @var array{install_path?: string, ...} $root */
            $root = InstalledVersions::getRootPackage();
            $installPath = $root['install_path'] ?? null;
            if (!\is_string($installPath)) {
                return [];
            }

            $path = \rtrim($installPath, \DIRECTORY_SEPARATOR . '/') . '/' . $this->resolveComposerFileName();
            if (!\is_file($path)) {
                return [];
            }

            $contents = \file_get_contents($path);
            if ($contents === false) {
                return [];
            }

            /** @var mixed $decoded */
            $decoded = \json_decode($contents, true);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Honor the documented `COMPOSER` environment variable for composer.json
     * filename overrides (matches what Composer itself and phpstan do).
     *
     * Hardened with `basename()` — the envvar may only redirect to a different
     * filename within the project root, never to an absolute path or traversal.
     *
     * @psalm-pure
     */
    private function resolveComposerFileName(): string
    {
        $envFile = \getenv('COMPOSER');
        if (!\is_string($envFile) || \trim($envFile) === '') {
            return 'composer.json';
        }

        return \basename(\trim($envFile));
    }

    /**
     * @param array<array-key, mixed> $nestedData
     * @param list<string> $keys
     *
     * @psalm-pure
     */
    private function readNestedString(array $nestedData, array $keys): ?string
    {
        /** @psalm-var mixed $cursor */
        $cursor = $nestedData;
        foreach ($keys as $key) {
            if (!\is_array($cursor) || !\array_key_exists($key, $cursor)) {
                return null;
            }

            /** @psalm-var mixed $cursor */
            $cursor = $cursor[$key];
        }

        return \is_string($cursor) ? $cursor : null;
    }
}

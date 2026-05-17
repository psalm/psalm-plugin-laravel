<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application as LaravelApplication;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\LaravelPlugin\Util\StubFileFinder;
use Psalm\Progress\VoidProgress;

/**
 * Collects runtime introspection data about the plugin's resolved state.
 *
 * Reused by every {@see Renderer} implementation; the renderer is the only
 * thing that changes between text / json / markdown output. Returns plain
 * associative arrays so renderers stay decoupled from a value-object shape.
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
 * @psalm-type StubDir = array{
 *     dir: string,
 *     reason: string,
 *     file_count: int,
 * }
 * @psalm-type IntegrationEntry = array{
 *     package: string,
 *     installed: bool,
 *     version: ?string,
 *     satisfies: ?bool,
 *     constraint: ?string,
 *     note: string,
 * }
 * @psalm-type SchemaSection = array{
 *     state: 'warm'|'cold',
 *     migration_dirs: list<string>,
 *     migration_file_count: int,
 *     tables_parsed: ?int,
 * }
 * @psalm-type Report = array{
 *     versions: VersionSection,
 *     boot: BootSection,
 *     stubs: list<StubDir>,
 *     integrations: list<IntegrationEntry>,
 *     handlers: array{categories: array<string, int>, total: int},
 *     schema: SchemaSection,
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

        $stubs = $this->collectStubDirs();
        $handlers = $this->collectHandlerCounts();
        $hardFailures = [];

        if ($bootError !== null) {
            $hardFailures[] = "Application boot failed: {$bootError}";
        }

        if ($stubs === []) {
            $hardFailures[] = 'No stub directories resolved — plugin would register zero stubs.';
        }

        if ($handlers['total'] === 0) {
            $hardFailures[] = 'No handler classes discovered in src/Handlers/.';
        }

        return [
            'versions' => $this->collectVersions(),
            'boot' => [
                'mode' => ApplicationProvider::getBootMode()?->value,
                'description' => ApplicationProvider::getBootMode()?->describe(),
                'path' => ApplicationProvider::getBootPath(),
                'error' => $bootError,
            ],
            'stubs' => $stubs,
            'integrations' => $this->collectIntegrations(),
            'handlers' => $handlers,
            'schema' => $this->collectSchema($bootError === null),
            'hard_failures' => $hardFailures,
        ];
    }

    /**
     * @return VersionSection
     */
    private function collectVersions(): array
    {
        return [
            'plugin' => $this->safePrettyVersion(self::PLUGIN_PACKAGE),
            'laravel' => \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : null,
            'psalm' => $this->safePrettyVersion('vimeo/psalm'),
            'php' => \PHP_VERSION,
        ];
    }

    /**
     * @return list<StubDir>
     */
    private function collectStubDirs(): array
    {
        $stubsRoot = \dirname(__DIR__, 3) . '/stubs';
        $progress = new VoidProgress();
        $laravelVersion = \defined(LaravelApplication::class . '::VERSION') ? LaravelApplication::VERSION : '0.0.0';

        $result = [];

        $commonDir = $stubsRoot . '/common';
        if (\is_dir($commonDir)) {
            $result[] = [
                'dir' => $commonDir,
                'reason' => 'common (always loaded)',
                'file_count' => \count(StubFileFinder::commonStubs($stubsRoot, $progress)),
            ];
        }

        // Replicate Plugin::registerStubs() version selection so the report
        // matches what a real Psalm run would register, per-versioned-dir.
        $candidates = [];
        if (\is_dir($stubsRoot)) {
            foreach (new \DirectoryIterator($stubsRoot) as $entry) {
                if (!$entry->isDir() || $entry->isDot()) {
                    continue;
                }
                $name = $entry->getFilename();
                if ($name !== '' && \ctype_digit($name[0])) {
                    $candidates[] = $name;
                }
            }
        }

        foreach (StubFileFinder::filterVersionDirectories($candidates, $laravelVersion) as $versionDir) {
            $dirPath = $stubsRoot . '/' . $versionDir;
            $result[] = [
                'dir' => $dirPath,
                'reason' => "version_compare('{$versionDir}', '{$laravelVersion}', '<=') matched",
                'file_count' => \count(StubFileFinder::findIn($dirPath, $progress)),
            ];
        }

        return $result;
    }

    /**
     * Probe opt-in / optional integrations. Currently only Carbon is auto-detected.
     *
     * @return list<IntegrationEntry>
     */
    private function collectIntegrations(): array
    {
        $packages = ['nesbot/carbon'];
        $entries = [];

        foreach ($packages as $package) {
            $installed = InstalledVersions::isInstalled($package);
            $version = $installed ? $this->safePrettyVersion($package) : null;
            $entries[] = [
                'package' => $package,
                'installed' => $installed,
                'version' => $version,
                'satisfies' => $installed ? true : null,
                'constraint' => null,
                'note' => $installed
                    ? 'CarbonStubProvider registers DatePeriodBase / LazyTranslator / LazyMessageFormatter stubs.'
                    : 'Not installed — Carbon stubs are skipped (no impact on non-Carbon projects).',
            ];
        }

        return $entries;
    }

    /**
     * @return array{categories: array<string, int>, total: int}
     */
    private function collectHandlerCounts(): array
    {
        $handlersRoot = \dirname(__DIR__, 3) . '/src/Handlers';

        if (!\is_dir($handlersRoot)) {
            return ['categories' => [], 'total' => 0];
        }

        $categories = [];
        $total = 0;

        foreach (new \DirectoryIterator($handlersRoot) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $categories['(root)'] = ($categories['(root)'] ?? 0) + 1;
                $total++;
                continue;
            }

            if (!$entry->isDir()) {
                continue;
            }

            $count = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($entry->getPathname(), \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                }
            }

            $categories[$entry->getFilename()] = $count;
            $total += $count;
        }

        \ksort($categories);

        return ['categories' => $categories, 'total' => $total];
    }

    /**
     * @return SchemaSection
     */
    private function collectSchema(bool $appBooted): array
    {
        $aggregator = SchemaStateProvider::getSchema();

        $migrationDirs = [];
        $migrationFileCount = 0;

        if ($appBooted) {
            $app = ApplicationProvider::getApp();

            if (\method_exists($app, 'databasePath')) {
                $candidate = $app->databasePath('migrations');
                if (\is_dir($candidate)) {
                    $migrationDirs[] = $candidate;

                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($candidate, \FilesystemIterator::SKIP_DOTS),
                    );

                    /** @var \SplFileInfo $file */
                    foreach ($iterator as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $migrationFileCount++;
                        }
                    }
                }
            }
        }

        return [
            'state' => $aggregator !== null ? 'warm' : 'cold',
            'migration_dirs' => $migrationDirs,
            'migration_file_count' => $migrationFileCount,
            // SchemaAggregator does not expose a table count publicly; the diagnose CLI
            // does not run MigrationSchemaBuilder (it requires a live ProjectAnalyzer).
            'tables_parsed' => null,
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

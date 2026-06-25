<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Foundation\Application;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Progress\Progress;

/**
 * Builds the {@see SchemaAggregator} for the booted Laravel app.
 *
 * Discovers SQL schema dumps and PHP migration files, parses them through
 * {@see SqlSchemaParser} and {@see SchemaAggregator}, and serves results
 * through a {@see MigrationCache} so subsequent runs skip re-parsing when
 * neither the migrations nor the plugin version have changed.
 *
 * @internal
 */
final class MigrationSchemaBuilder
{
    /** @psalm-mutation-free */
    public function __construct(
        private readonly Application $app,
        private readonly Codebase $codebase,
        private readonly MigrationCache $cache,
    ) {}

    public function build(): SchemaAggregator
    {
        $progress = $this->codebase->progress;

        // Discover all files first — needed for cache fingerprinting
        $sqlDumpFiles = $this->discoverSqlDumpFiles($progress);
        $migrationFiles = $this->discoverMigrationFiles($progress);

        $tables = $this->cache->remember($migrationFiles, $sqlDumpFiles, fn(): array => $this->parse(
            $sqlDumpFiles,
            $migrationFiles,
            $progress,
        ));

        $this->reportCacheStatus($progress);

        $aggregator = new SchemaAggregator();
        $aggregator->tables = $tables;
        return $aggregator;
    }

    /**
     * @param list<string> $sqlDumpFiles
     * @param list<string> $migrationFiles
     * @return array<string, SchemaTable>
     */
    private function parse(array $sqlDumpFiles, array $migrationFiles, Progress $progress): array
    {
        $aggregator = new SchemaAggregator();

        // Parse SQL schema dumps first — they represent the base state from
        // squashed migrations (php artisan schema:dump)
        $this->parseSqlDumps($sqlDumpFiles, $aggregator, $progress);

        // Then parse PHP migrations — they modify the base state
        foreach ($migrationFiles as $file) {
            try {
                $aggregator->addStatements($this->codebase->getStatementsForFile($file));
            } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
                $progress->warning("Laravel plugin: skipping migration '{$file}': {$e->getMessage()}");
            }
        }

        return $aggregator->tables;
    }

    private function reportCacheStatus(Progress $progress): void
    {
        if ($this->cache->wasCacheHit()) {
            $progress->debug("Laravel plugin: loaded migration schema from cache\n");
        } elseif ($this->cache->wasCacheWritten()) {
            $progress->debug("Laravel plugin: parsed migration schema (cached for next run)\n");
        } else {
            $writeFailure = $this->cache->getWriteFailureReason();
            $detail = $writeFailure !== null ? ": {$writeFailure}" : ' — check directory permissions';
            $progress->warning("Laravel plugin: parsed migration schema (cache write failed{$detail})");
        }

        $readFailure = $this->cache->getReadFailureReason();
        if ($readFailure !== null) {
            $progress->warning("Laravel plugin: {$readFailure}");
        }
    }

    /**
     * Discover SQL schema dump files from the database/schema/ directory.
     *
     * @return list<string>
     */
    private function discoverSqlDumpFiles(Progress $progress): array
    {
        $schemaDir = $this->app->databasePath('schema');

        if (!\is_dir($schemaDir)) {
            return [];
        }

        return $this->findSqlDumpFiles($schemaDir, $progress);
    }

    /**
     * Discover PHP migration files from all registered migration directories.
     *
     * @return list<string>
     */
    private function discoverMigrationFiles(Progress $progress): array
    {
        $files = [];

        foreach ($this->getMigrationDirectories($progress) as $directory) {
            \array_push($files, ...$this->findPhpFilesRecursive($directory, $progress));
        }

        // Sort by basename to match Laravel's migrator ordering (timestamp prefixes
        // ensure chronological order). Without sorting, RecursiveIteratorIterator
        // returns files in filesystem order (not alphabetical on ext4/Linux), causing
        // Schema::table() to run before the corresponding Schema::create().
        \usort($files, static fn(string $a, string $b): int => \basename($a) <=> \basename($b));

        return $files;
    }

    /**
     * Parse SQL schema dump files into the aggregator.
     *
     * Laravel's `php artisan schema:dump` creates these files using mysqldump/pg_dump.
     * They represent squashed migrations and should be parsed before PHP migrations.
     *
     * @param list<string> $sqlDumpFiles
     */
    private function parseSqlDumps(array $sqlDumpFiles, SchemaAggregator $schemaAggregator, Progress $progress): void
    {
        if ($sqlDumpFiles === []) {
            return;
        }

        $sqlParser = new SqlSchemaParser();

        foreach ($sqlDumpFiles as $file) {
            try {
                $sql = \file_get_contents($file);

                if ($sql === false) {
                    $progress->warning("Laravel plugin: could not read SQL schema dump '{$file}'");
                    continue;
                }

                $sqlParser->addToAggregator($sql, $schemaAggregator);
            } catch (\RuntimeException $exception) {
                // SqlSchemaParser is pure string processing and shouldn't throw.
                // This catch is a safety net for unexpected runtime issues (e.g. memory).
                $progress->warning("Laravel plugin: skipping SQL schema dump '{$file}': {$exception->getMessage()}");
            }
        }
    }

    /**
     * Find SQL schema dump files in a directory (non-recursive — schema dumps are flat).
     *
     * The .dump extension is supported for backward compatibility with schema dumps
     * created by older Laravel versions. Both extensions contain plain-text SQL.
     *
     * @return list<string>
     */
    private function findSqlDumpFiles(string $directory, Progress $progress): array
    {
        try {
            $iterator = new \DirectoryIterator($directory);
        } catch (\UnexpectedValueException $unexpectedValueException) {
            $progress->warning(
                "Laravel plugin: could not read schema directory '{$directory}': {$unexpectedValueException->getMessage()}",
            );
            return [];
        }

        $files = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || !\in_array($fileInfo->getExtension(), ['sql', 'dump'], true)) {
                continue;
            }

            $realPath = $fileInfo->getRealPath();

            if (\is_string($realPath)) {
                $files[] = $realPath;
            }
        }

        // Sort for deterministic loading order — later files can overwrite tables from earlier ones
        \sort($files);

        return $files;
    }

    /**
     * Resolve migration directories the same way Laravel does:
     * extra paths registered via loadMigrationsFrom() + the default database/migrations directory.
     *
     * `migrator` is a *deferred* service (MigrationServiceProvider implements DeferrableProvider),
     * so it only becomes resolvable once the RegisterProviders bootstrapper builds the app's
     * deferred-services map. The plugin deliberately tolerates a partial bootstrap
     * (see ApplicationProvider), which can leave that map incomplete — `make('migrator')` would
     * then throw BindingResolutionException and abort the whole migration-schema feature (#1170).
     *
     * Guard with `bound()` exactly like the sibling init methods (translator/view in Plugin.php):
     * `Application::bound()` already accounts for deferred services and partial bootstrap, so an
     * unresolvable migrator degrades to the default migrations directory instead of crashing.
     *
     * @return non-empty-list<string>
     */
    private function getMigrationDirectories(Progress $progress): array
    {
        $defaultDirectory = $this->app->databasePath('migrations');

        if (!$this->app->bound('migrator')) {
            $progress->warning($this->migratorUnavailableWarning());

            return [$defaultDirectory];
        }

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = $this->app->make('migrator');

        return \array_values(\array_merge($migrator->paths(), [$defaultDirectory]));
    }

    /**
     * Compose the migrator-unavailable warning, enriched with the boot diagnostics
     * the plugin already captured.
     *
     * This degrades gracefully instead of reaching {@see InternalErrorReporter}, so the
     * one datum that actually explains *why* the deferred-services map is incomplete —
     * the bootstrap throwable the plugin swallowed to tolerate a partial boot — is pulled
     * in here from {@see ApplicationProvider}. Without it the user sees only the symptom
     * (migrator missing) and not the root cause (the bad config/provider that aborted boot).
     *
     * @psalm-external-mutation-free
     */
    private function migratorUnavailableWarning(): string
    {
        $bootMode = ApplicationProvider::getBootMode();
        $mode = $bootMode !== null ? " (boot mode: {$bootMode})" : '';

        $bootstrapError = ApplicationProvider::getBootstrapError();
        $cause = $bootstrapError instanceof \Throwable
            ? " The Laravel bootstrap did not complete: {$bootstrapError->getMessage()}."
            : '';

        return "Laravel plugin: the 'migrator' service is not bound{$mode}, so migration paths "
            . 'registered via loadMigrationsFrom() cannot be auto-discovered.' . $cause
            . ' Only the default database/migrations directory will be scanned — fix the bootstrap '
            . 'error above or declare the extra paths another way.';
    }

    /**
     * Recursively find all .php files in a directory.
     *
     * @return list<string>
     */
    private function findPhpFilesRecursive(string $directory, Progress $progress): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ));
        } catch (\UnexpectedValueException $unexpectedValueException) {
            $progress->warning(
                "Laravel plugin: could not read migration directory '{$directory}': {$unexpectedValueException->getMessage()}",
            );
            return [];
        }

        $files = [];

        try {
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $realPath = $file->getRealPath();
                if (\is_string($realPath)) {
                    $files[] = $realPath;
                }
            }
        } catch (\UnexpectedValueException $unexpectedValueException) {
            // RecursiveIteratorIterator can throw during iteration on unreadable subdirectories
            $progress->warning(
                "Laravel plugin: error scanning migration directory '{$directory}': {$unexpectedValueException->getMessage()}",
            );
        }

        return $files;
    }
}

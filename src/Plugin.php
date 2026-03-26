<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Handlers\Application\ContainerHandler;
use Psalm\LaravelPlugin\Handlers\Application\OffsetHandler;
use Psalm\LaravelPlugin\Handlers\Auth\AuthHandler;
use Psalm\LaravelPlugin\Handlers\Auth\GuardHandler;
use Psalm\LaravelPlugin\Handlers\Auth\RequestHandler;
use Psalm\LaravelPlugin\Handlers\Collections\CollectionFilterHandler;
use Psalm\LaravelPlugin\Handlers\Collections\CollectionPluckHandler;
use Psalm\LaravelPlugin\Handlers\Console\CommandArgumentHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\PluckHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationsMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\MigrationCache;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SqlSchemaParser;
use Psalm\LaravelPlugin\Handlers\Helpers\CacheHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\PathHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\TransHandler;
use Psalm\LaravelPlugin\Handlers\Rules\NoEnvOutsideConfigHandler;
use Psalm\LaravelPlugin\Handlers\SuppressHandler;
use Psalm\LaravelPlugin\Handlers\Validation\ValidatedTypeHandler;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationTaintHandler;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\LaravelPlugin\Util\IssueUrlGenerator;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * @psalm-api
 * @internal
 */
final class Plugin implements PluginEntryPointInterface
{
    /** @inheritDoc */
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        $pluginConfig = PluginConfig::fromXml($config);
        $output = $this->getProgress($registration);

        if (\getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH') !== false) {
            $output->warning(
                'Laravel plugin: PSALM_LARAVEL_PLUGIN_CACHE_PATH is deprecated and will be removed in v5. '
                . "The plugin now uses Psalm's cache directory automatically.",
            );
        }

        try {
            ApplicationProvider::bootApp();

            if ($pluginConfig->shouldUseMigrations()) {
                $this->buildSchema($pluginConfig);
            }

            $this->generateAliasStubs($pluginConfig);

            NoEnvOutsideConfigHandler::init(
                ApplicationProvider::getApp()->configPath(),
            );

            $this->registerHandlers($registration, $pluginConfig);
            $this->registerStubs($registration, $pluginConfig);
        } catch (\Throwable $throwable) {
            $this->handleInternalError($throwable, $output, $pluginConfig->failOnInternalError);
        }
    }

    /** @return list<string> */
    private function getCommonStubs(): array
    {
        return $this->findStubFiles(\dirname(__DIR__) . '/stubs/common');
    }

    /** @return list<string> */
    private function getStubsForLaravelVersion(string $version): array
    {
        [$majorVersion] = \explode('.', $version);

        return $this->findStubFiles(\dirname(__DIR__) . '/stubs/' . $majorVersion);
    }

    /**
     * Recursively find all .stubphp files in a directory.
     *
     * Results are sorted to ensure deterministic stub registration order.
     * RecursiveDirectoryIterator returns files in filesystem order, which
     * varies across OSes (alphabetical on APFS/HFS+, inode order on ext4).
     *
     * Stub loading order matters: when multiple stubs declare the same method
     * on the same class, Psalm reuses the MethodStorage and re-applies docblock
     * parsing. Type annotations (`@return`, `@param`) use `=` so the last-loaded
     * stub wins; taint annotations (`@psalm-taint-*`) use `|=` and accumulate.
     * Without sorting, moving or renaming stub files can silently change types.
     * See docs/contribute/README.md "Stub merging" for details.
     *
     * @return list<string>
     */
    private function findStubFiles(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $stubs = [];

        try {
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'stubphp') {
                    continue;
                }

                $realPath = $file->getRealPath();

                if (!\is_string($realPath)) {
                    continue;
                }

                $stubs[] = $realPath;
            }
        } catch (\UnexpectedValueException) {
            // RecursiveIteratorIterator can throw during iteration on unreadable subdirectories.
            // Return whatever stubs were collected before the error — partial results from
            // readable subdirectories are better than none.
        }

        \sort($stubs);

        return $stubs;
    }

    private function registerStubs(RegistrationInterface $registration, PluginConfig $pluginConfig): void
    {
        $stubs = \array_merge(
            $this->getCommonStubs(),
            $this->getStubsForLaravelVersion(Application::VERSION),
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        $registration->addStubFile(self::getAliasStubLocation($pluginConfig));
    }

    private function registerHandlers(RegistrationInterface $registration, PluginConfig $pluginConfig): void
    {
        require_once __DIR__ . '/Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(ContainerHandler::class);
        require_once __DIR__ . '/Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(OffsetHandler::class);

        require_once __DIR__ . '/Handlers/Auth/AuthHandler.php';
        $registration->registerHooksFromClass(AuthHandler::class);
        require_once __DIR__ . '/Handlers/Auth/GuardHandler.php';
        $registration->registerHooksFromClass(GuardHandler::class);
        require_once __DIR__ . '/Handlers/Auth/RequestHandler.php';
        $registration->registerHooksFromClass(RequestHandler::class);

        // Model property handlers are registered dynamically by ModelRegistrationHandler
        // after Psalm populates its codebase (AfterCodebasePopulated event).
        require_once __DIR__ . '/Handlers/Eloquent/ModelRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        if ($pluginConfig->shouldUseMigrations()) {
            require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
            ModelRegistrationHandler::enableMigrations();
        }

        $registration->registerHooksFromClass(ModelRegistrationHandler::class);

        require_once __DIR__ . '/Handlers/Eloquent/RelationsMethodHandler.php';
        $registration->registerHooksFromClass(RelationsMethodHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(ModelMethodHandler::class);
        require_once __DIR__ . '/Util/ModelPropertyResolver.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderScopeHandler.php';
        $registration->registerHooksFromClass(BuilderScopeHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/PluckHandler.php';
        $registration->registerHooksFromClass(PluckHandler::class);

        require_once __DIR__ . '/Handlers/Collections/CollectionFilterHandler.php';
        $registration->registerHooksFromClass(CollectionFilterHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionPluckHandler.php';
        $registration->registerHooksFromClass(CollectionPluckHandler::class);

        require_once __DIR__ . '/Handlers/Console/CommandArgumentHandler.php';
        $registration->registerHooksFromClass(CommandArgumentHandler::class);

        require_once __DIR__ . '/Handlers/Validation/ValidatedTypeHandler.php';
        $registration->registerHooksFromClass(ValidatedTypeHandler::class);
        require_once __DIR__ . '/Handlers/Validation/ValidationTaintHandler.php';
        $registration->registerHooksFromClass(ValidationTaintHandler::class);

        require_once __DIR__ . '/Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(CacheHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(PathHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/TransHandler.php';
        $registration->registerHooksFromClass(TransHandler::class);

        require_once __DIR__ . '/Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(SuppressHandler::class);

        require_once __DIR__ . '/Handlers/Rules/NoEnvOutsideConfigHandler.php';
        $registration->registerHooksFromClass(NoEnvOutsideConfigHandler::class);
    }

    private function buildSchema(PluginConfig $pluginConfig): void
    {
        $app = ApplicationProvider::getApp();

        if (!\method_exists($app, 'databasePath')) {
            return;
        }

        $projectAnalyzer = ProjectAnalyzer::getInstance();
        $codebase = $projectAnalyzer->getCodebase();
        $progress = $codebase->progress;

        // Discover all files first — needed for cache fingerprinting
        $sqlDumpFiles = $this->discoverSqlDumpFiles($app, $progress);
        $migrationFiles = $this->discoverMigrationFiles($app, $progress);

        $cache = new MigrationCache(self::getCacheLocation($pluginConfig));

        $tables = $cache->remember(
            $migrationFiles,
            $sqlDumpFiles,
            function () use ($sqlDumpFiles, $migrationFiles, $codebase, $progress): array {
                $schemaAggregator = new SchemaAggregator();

                // Parse SQL schema dumps first — they represent the base state from
                // squashed migrations (php artisan schema:dump)
                $this->parseSqlDumps($sqlDumpFiles, $schemaAggregator, $progress);

                // Then parse PHP migrations — they modify the base state
                foreach ($migrationFiles as $file) {
                    try {
                        $schemaAggregator->addStatements($codebase->getStatementsForFile($file));
                    } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
                        $progress->warning(
                            "Laravel plugin: skipping migration '{$file}': {$e->getMessage()}",
                        );
                    }
                }

                return $schemaAggregator->tables;
            },
        );

        if ($cache->wasCacheHit()) {
            $progress->debug("Laravel plugin: loaded migration schema from cache\n");
        } elseif ($cache->wasCacheWritten()) {
            $progress->debug("Laravel plugin: parsed migration schema (cached for next run)\n");
        } else {
            $writeFailure = $cache->getWriteFailureReason();
            $detail = $writeFailure !== null ? ": {$writeFailure}" : ' — check directory permissions';
            $progress->warning("Laravel plugin: parsed migration schema (cache write failed{$detail})");
        }

        $readFailure = $cache->getReadFailureReason();
        if ($readFailure !== null) {
            $progress->warning("Laravel plugin: {$readFailure}");
        }

        $schemaAggregator = new SchemaAggregator();
        $schemaAggregator->tables = $tables;
        SchemaStateProvider::setSchema($schemaAggregator);
    }

    /**
     * Discover SQL schema dump files from the database/schema/ directory.
     *
     * @return list<string>
     */
    private function discoverSqlDumpFiles(Application $app, \Psalm\Progress\Progress $progress): array
    {
        $schemaDir = $app->databasePath('schema');

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
    private function discoverMigrationFiles(Application $app, \Psalm\Progress\Progress $progress): array
    {
        $files = [];

        foreach ($this->getMigrationDirectories($app) as $directory) {
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
    private function parseSqlDumps(
        array $sqlDumpFiles,
        SchemaAggregator $schemaAggregator,
        \Psalm\Progress\Progress $progress,
    ): void {
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
    private function findSqlDumpFiles(string $directory, \Psalm\Progress\Progress $progress): array
    {
        try {
            $iterator = new \DirectoryIterator($directory);
        } catch (\UnexpectedValueException $unexpectedValueException) {
            $progress->warning("Laravel plugin: could not read schema directory '{$directory}': {$unexpectedValueException->getMessage()}");
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
     * @return non-empty-list<string>
     */
    private function getMigrationDirectories(Application $app): array
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = $app->make('migrator');

        return \array_values(\array_merge($migrator->paths(), [$app->databasePath('migrations')]));
    }

    /**
     * Recursively find all .php files in a directory.
     * @return list<string>
     */
    private function findPhpFilesRecursive(string $directory, \Psalm\Progress\Progress $progress): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
        } catch (\UnexpectedValueException $unexpectedValueException) {
            $progress->warning("Laravel plugin: could not read migration directory '{$directory}': {$unexpectedValueException->getMessage()}");
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
            $progress->warning("Laravel plugin: error scanning migration directory '{$directory}': {$unexpectedValueException->getMessage()}");
        }

        return $files;
    }

    private function generateAliasStubs(PluginConfig $pluginConfig): void
    {
        // Read aliases from the booted app's AliasLoader — this reflects the actual
        // aliases registered for this project (config app.aliases + package discovery),
        // not just Laravel's hardcoded defaults.
        /** @var array<string, class-string> $aliases */
        $aliases = \Illuminate\Foundation\AliasLoader::getInstance()->getAliases();
        $stub = "<?php\n\n";

        foreach ($aliases as $alias => $fqcn) {
            // Skip namespaced aliases — `class Some\Name extends ...` is invalid PHP
            // without a namespace block
            if (\str_contains($alias, '\\')) {
                continue;
            }

            $stub .= "class {$alias} extends \\{$fqcn} {}\n";
        }

        $location = self::getAliasStubLocation($pluginConfig);
        $result = \file_put_contents($location, $stub);

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to write alias stub file to '{$location}'. "
                . 'Check that the directory exists and is writable.',
            );
        }
    }

    public static function getAliasStubLocation(PluginConfig $pluginConfig): string
    {
        return self::getCacheLocation($pluginConfig) . \DIRECTORY_SEPARATOR . 'aliases.stubphp';
    }

    public static function getCacheLocation(PluginConfig $pluginConfig): string
    {
        $dir = $pluginConfig->cachePath;

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cache directory '{$dir}' does not exist and could not be created.");
        }

        return $dir;
    }

    /** @throws \Throwable */
    private function handleInternalError(\Throwable $throwable, \Psalm\Progress\Progress $output, bool $failOnInternalError): void
    {
        $output->warning("Laravel plugin error on initialisation: {$throwable->getMessage()}");
        $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . IssueUrlGenerator::generate($throwable));

        if ($failOnInternalError) {
            throw $throwable;
        }
    }

    /** @psalm-mutation-free */
    private function getProgress(RegistrationInterface $registration): \Psalm\Progress\Progress
    {
        $output = new \Psalm\Progress\DefaultProgress();

        // $registration->codebase is available/public from Psalm v6.7
        // see https://github.com/vimeo/psalm/pull/11297 and https://github.com/vimeo/psalm/releases/tag/6.7.0
        if ($registration instanceof \Psalm\PluginRegistrationSocket) {
            $output = $registration->codebase->progress;
        }

        return $output;
    }
}

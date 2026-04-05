<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SqlSchemaParser;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
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

            // Build facade → service class map before registering handlers.
            // Handlers use FacadeMapProvider::getFacadeClasses() in getClassLikeNames()
            // to also register for facade/alias classes that proxy to their service.
            FacadeMapProvider::init($output);

            Handlers\Rules\NoEnvOutsideConfigHandler::init(
                ApplicationProvider::getApp()->configPath(),
            );

            // Always called — provides type narrowing (string vs array) regardless
            // of whether findMissingTranslations is enabled
            $this->initTranslationKeyHandler($output, $pluginConfig->findMissingTranslations);

            if ($pluginConfig->findMissingViews) {
                $this->initMissingViewHandler($output);
            }

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
     * See docs/contributing/README.md "Stub merging" for details.
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
        $registration->registerHooksFromClass(Handlers\Application\ContainerHandler::class);
        require_once __DIR__ . '/Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(Handlers\Application\OffsetHandler::class);

        require_once __DIR__ . '/Handlers/Auth/AuthHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\AuthHandler::class);
        require_once __DIR__ . '/Handlers/Auth/GuardHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\GuardHandler::class);
        require_once __DIR__ . '/Handlers/Auth/RequestHandler.php';
        $registration->registerHooksFromClass(Handlers\Auth\RequestHandler::class);

        // Model property handlers are registered dynamically by ModelRegistrationHandler
        // after Psalm populates its codebase (AfterCodebasePopulated event).
        require_once __DIR__ . '/Handlers/Eloquent/ModelRegistrationHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/CustomCollectionHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        if ($pluginConfig->shouldUseMigrations()) {
            require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
            Handlers\Eloquent\ModelRegistrationHandler::enableMigrations();
        }

        $registration->registerHooksFromClass(Handlers\Eloquent\ModelRegistrationHandler::class);

        // Magic method forwarding: Relation -> Builder (decorated forwarding).
        // Must be registered BEFORE BuilderScopeHandler, BuilderPluckHandler, and
        // CustomCollectionHandler — the handler returns null for non-Relation callers
        // (fast O(1) check), so downstream handlers fire unaffected.
        require_once __DIR__ . '/Handlers/Magic/ForwardingRule.php';
        require_once __DIR__ . '/Handlers/Magic/ReturnTypeResolver.php';
        require_once __DIR__ . '/Handlers/Magic/MethodForwardingHandler.php';
        Handlers\Magic\MethodForwardingHandler::init(new Handlers\Magic\ForwardingRule(
            sourceClass: \Illuminate\Database\Eloquent\Relations\Relation::class,
            searchClasses: [
                \Illuminate\Database\Eloquent\Builder::class,
                \Illuminate\Database\Query\Builder::class,
            ],
            selfReturnIndicators: [\Illuminate\Database\Eloquent\Builder::class],
            // Relation subclasses (concrete + abstract bases, since Psalm hook lookup
            // is exact-class). MorphPivot is in the Relations namespace but extends
            // Model (not Relation) — intentionally excluded.
            additionalSourceClasses: [
                \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
                \Illuminate\Database\Eloquent\Relations\BelongsToMany::class,
                \Illuminate\Database\Eloquent\Relations\HasMany::class,
                \Illuminate\Database\Eloquent\Relations\HasManyThrough::class,
                \Illuminate\Database\Eloquent\Relations\HasOne::class,
                \Illuminate\Database\Eloquent\Relations\HasOneOrMany::class,
                \Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough::class,
                \Illuminate\Database\Eloquent\Relations\HasOneThrough::class,
                \Illuminate\Database\Eloquent\Relations\MorphMany::class,
                \Illuminate\Database\Eloquent\Relations\MorphOne::class,
                \Illuminate\Database\Eloquent\Relations\MorphOneOrMany::class,
                \Illuminate\Database\Eloquent\Relations\MorphTo::class,
                \Illuminate\Database\Eloquent\Relations\MorphToMany::class,
            ],
            interceptMixin: true,
        ));
        $registration->registerHooksFromClass(Handlers\Magic\MethodForwardingHandler::class);

        require_once __DIR__ . '/Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\ModelMethodHandler::class);
        require_once __DIR__ . '/Util/ModelPropertyResolver.php';
        require_once __DIR__ . '/Handlers/Eloquent/BuilderScopeHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderScopeHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderPluckHandler.php';
        $registration->registerHooksFromClass(Handlers\Eloquent\BuilderPluckHandler::class);
        $registration->registerHooksFromClass(Handlers\Eloquent\CustomCollectionHandler::class);

        require_once __DIR__ . '/Handlers/Collections/CollectionFilterHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFilterHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionFlattenHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionFlattenHandler::class);
        require_once __DIR__ . '/Handlers/Collections/CollectionPluckHandler.php';
        $registration->registerHooksFromClass(Handlers\Collections\CollectionPluckHandler::class);

        require_once __DIR__ . '/Handlers/Console/CommandArgumentHandler.php';
        $registration->registerHooksFromClass(Handlers\Console\CommandArgumentHandler::class);

        require_once __DIR__ . '/Handlers/Validation/ValidatedTypeHandler.php';
        $registration->registerHooksFromClass(Handlers\Validation\ValidatedTypeHandler::class);
        require_once __DIR__ . '/Handlers/Validation/ValidationTaintHandler.php';
        $registration->registerHooksFromClass(Handlers\Validation\ValidationTaintHandler::class);

        require_once __DIR__ . '/Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\CacheHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(Handlers\Helpers\PathHandler::class);
        require_once __DIR__ . '/Handlers/Translations/TranslationKeyHandler.php';
        $registration->registerHooksFromClass(Handlers\Translations\TranslationKeyHandler::class);

        require_once __DIR__ . '/Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(Handlers\SuppressHandler::class);

        require_once __DIR__ . '/Handlers/Rules/ModelMakeHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\ModelMakeHandler::class);
        require_once __DIR__ . '/Handlers/Rules/NoEnvOutsideConfigHandler.php';
        $registration->registerHooksFromClass(Handlers\Rules\NoEnvOutsideConfigHandler::class);

        // Unlike TranslationKeyHandler (which always runs for type narrowing),
        // MissingViewHandler provides no type information — skip entirely when disabled
        if ($pluginConfig->findMissingViews) {
            require_once __DIR__ . '/Handlers/Views/MissingViewHandler.php';
            $registration->registerHooksFromClass(Handlers\Views\MissingViewHandler::class);
        }
    }

    /**
     * Get the Translator instance from the booted Laravel app and pass it to the handler.
     *
     * Uses Laravel's Translator::has() for key resolution, which handles PHP array files,
     * JSON files, vendor/package namespaces, and fallback locales automatically.
     *
     * Always called to enable precise type narrowing (string vs array) for translation
     * keys. The $reportMissing flag controls only whether MissingTranslation issues
     * are emitted for keys that don't exist.
     */
    private function initTranslationKeyHandler(\Psalm\Progress\Progress $output, bool $reportMissing): void
    {
        $app = ApplicationProvider::getApp();

        if (!$app->bound('translator')) {
            // Only warn when the user explicitly opted into missing translation detection —
            // without it, they just lose the bonus type narrowing, which isn't worth a warning
            if ($reportMissing) {
                $output->warning(
                    'Laravel plugin: findMissingTranslations is enabled but the translator service is not bound. '
                    . 'The MissingTranslation check will be skipped.',
                );
            }

            return;
        }

        $translator = $app->make('translator');

        if (!$translator instanceof \Illuminate\Translation\Translator) {
            if ($reportMissing) {
                $output->warning(
                    'Laravel plugin: findMissingTranslations is enabled but the translator is not an instance of '
                    . 'Illuminate\Translation\Translator. The MissingTranslation check will be skipped.',
                );
            }

            return;
        }

        Handlers\Translations\TranslationKeyHandler::init($translator, $reportMissing);
    }

    /**
     * Read view paths from the booted Laravel app and pass them to the handler.
     *
     * Uses the app's FileViewFinder which reflects config('view.paths') plus
     * any paths added by service providers during bootstrap.
     */
    private function initMissingViewHandler(\Psalm\Progress\Progress $output): void
    {
        $app = ApplicationProvider::getApp();

        // Prefer the dedicated view.finder binding; fall back to the Factory's finder
        // (ApplicationProvider may bind 'view' without registering 'view.finder')
        if ($app->bound('view.finder')) {
            /** @var \Illuminate\View\FileViewFinder $finder */
            $finder = $app->make('view.finder');
        } elseif ($app->bound('view')) {
            $factory = $app->make('view');

            if (!$factory instanceof \Illuminate\View\Factory) {
                $output->warning(
                    'Laravel plugin: findMissingViews is enabled but the view factory is not a standard instance. '
                    . 'The MissingView check will be skipped.',
                );

                return;
            }

            $finder = $factory->getFinder();
        } else {
            $output->warning(
                'Laravel plugin: findMissingViews is enabled but the view finder service is not bound. '
                . 'The MissingView check will be skipped.',
            );

            return;
        }

        if (!$finder instanceof \Illuminate\View\FileViewFinder) {
            $output->warning(
                'Laravel plugin: findMissingViews is enabled but the view finder is not an instance of '
                . 'Illuminate\View\FileViewFinder. The MissingView check will be skipped.',
            );

            return;
        }

        /** @var list<string> $paths */
        $paths = $finder->getPaths();

        /** @var list<string> $extensions */
        $extensions = $finder->getExtensions();

        Handlers\Views\MissingViewHandler::init($paths, $extensions);
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

        $cache = new Handlers\Eloquent\Schema\MigrationCache(self::getCacheLocation($pluginConfig));

        $tables = $cache->remember(
            $migrationFiles,
            $sqlDumpFiles,
            function () use ($sqlDumpFiles, $migrationFiles, $codebase, $progress): array {
                $schemaAggregator = new Handlers\Eloquent\Schema\SchemaAggregator();

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

        $schemaAggregator = new Handlers\Eloquent\Schema\SchemaAggregator();
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

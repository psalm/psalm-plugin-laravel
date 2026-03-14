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
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelFactoryTypeProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyAccessorHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationshipPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationsMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Helpers\CacheHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\PathHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\TransHandler;
use Psalm\LaravelPlugin\Handlers\SuppressHandler;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\ModelDiscoveryProvider;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\LaravelPlugin\Util\IssueUrlGenerator;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\PluginRegistrationSocket;
use Psalm\Progress\DefaultProgress;

/**
 * @psalm-suppress UnusedClass
 * @internal
 */
final class Plugin implements PluginEntryPointInterface
{
    /** @inheritDoc */
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        $failOnInternalError = ((string) $config?->failOnInternalError) === 'true';
        $modelDiscoverySource = (string) ($config?->modelDiscovery['source'] ?? 'static');
        $output = new DefaultProgress();

        // $registration->codebase is available/public from Psalm v6.7
        // see https://github.com/vimeo/psalm/pull/11297 and https://github.com/vimeo/psalm/releases/tag/6.7.0
        if ($registration instanceof PluginRegistrationSocket) {
            $output = $registration->codebase->progress;
        }

        try {
            ApplicationProvider::bootApp();
        } catch (\Throwable $throwable) {
            $output->warning("Laravel plugin error on booting Laravel app: \u{201c}{$throwable->getMessage()}\u{201d}");
            $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . IssueUrlGenerator::generate($throwable));

            if ($failOnInternalError) {
                throw $throwable;
            }

            return;
        }

        try {
            $this->buildSchema();
            ModelDiscoveryProvider::discoverModels(ApplicationProvider::getApp());
            $this->generateAliasStubs();
        } catch (\Throwable $throwable) {
            $output->warning("Laravel plugin error on generating stub files: \u{201c}{$throwable->getMessage()}\u{201d}");
            $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . IssueUrlGenerator::generate($throwable));

            if ($failOnInternalError) {
                throw $throwable;
            }

            return;
        }

        $this->registerHandlers($registration, $modelDiscoverySource);
        $this->registerStubs($registration);
    }

    /** @return list<string> */
    private function getCommonStubs(): array
    {
        return $this->findStubFiles(\dirname(__DIR__) . '/stubs/common');
    }

    /** @return list<string> */
    private function getTaintAnalysisStubs(): array
    {
        return $this->findStubFiles(\dirname(__DIR__) . '/stubs/taintAnalysis');
    }

    /** @return list<string> */
    private function getStubsForVersion(string $version): array
    {
        [$majorVersion] = \explode('.', $version);

        return $this->findStubFiles(\dirname(__DIR__) . '/stubs/' . $majorVersion);
    }

    /**
     * Recursively find all .stubphp files in a directory.
     * @return list<string>
     */
    private function findStubFiles(string $directory): array
    {
        if (! \is_dir($directory)) {
            return [];
        }

        $stubs = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'stubphp') {
                continue;
            }

            $realPath = $file->getRealPath();

            if (! \is_string($realPath)) {
                continue;
            }

            $stubs[] = $realPath;
        }

        return $stubs;
    }

    private function registerStubs(RegistrationInterface $registration): void
    {
        $stubs = \array_merge(
            $this->getCommonStubs(),
            $this->getStubsForVersion(Application::VERSION),
            $this->getTaintAnalysisStubs(),
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        $registration->addStubFile(self::getAliasStubLocation());
    }

    private function registerHandlers(RegistrationInterface $registration, string $modelDiscoverySource): void
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

        // Model property handlers — registration order matters (first non-null wins)
        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        $registration->registerHooksFromClass(ModelRelationshipPropertyHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        $registration->registerHooksFromClass(ModelFactoryTypeProvider::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        $registration->registerHooksFromClass(ModelPropertyAccessorHandler::class);

        if ($modelDiscoverySource === 'static') {
            require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyHandler.php';
            $registration->registerHooksFromClass(ModelPropertyHandler::class);
        }

        require_once __DIR__ . '/Handlers/Eloquent/RelationsMethodHandler.php';
        $registration->registerHooksFromClass(RelationsMethodHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(ModelMethodHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/BuilderScopeHandler.php';
        $registration->registerHooksFromClass(BuilderScopeHandler::class);

        require_once __DIR__ . '/Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(CacheHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(PathHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/TransHandler.php';
        $registration->registerHooksFromClass(TransHandler::class);

        require_once __DIR__ . '/Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(SuppressHandler::class);
    }

    private function buildSchema(): void
    {
        $app = ApplicationProvider::getApp();

        if (!\method_exists($app, 'databasePath')) {
            return;
        }

        $migrationsDirectory = $app->databasePath('migrations/');

        $projectAnalyzer = ProjectAnalyzer::getInstance();
        $codebase = $projectAnalyzer->getCodebase();

        $schemaAggregator = new SchemaAggregator();

        $migrationFilePathnames = self::findPhpFilesRecursive($migrationsDirectory);
        if ($migrationFilePathnames === []) {
            SchemaStateProvider::setSchema($schemaAggregator);
            return;
        }

        foreach ($migrationFilePathnames as $file) {
            try {
                $schemaAggregator->addStatements($codebase->getStatementsForFile($file));
            } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
                $codebase->progress->debug(
                    "Laravel plugin: skipping migration '{$file}': {$e->getMessage()}\n",
                );
                continue;
            }
        }

        SchemaStateProvider::setSchema($schemaAggregator);
    }

    /**
     * Recursively find all .php files in a directory.
     *
     * @return list<string>
     */
    private static function findPhpFilesRecursive(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();
            if (\is_string($realPath)) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    private function generateAliasStubs(): void
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

        $location = self::getAliasStubLocation();
        $result = \file_put_contents($location, $stub);

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to write alias stub file to '{$location}'. "
                . 'Check that the directory exists and is writable. '
                . 'You can set PSALM_LARAVEL_PLUGIN_CACHE_PATH to specify a custom writable directory.',
            );
        }
    }

    public static function getAliasStubLocation(): string
    {
        return self::getCacheLocation() . \DIRECTORY_SEPARATOR . 'aliases.stubphp';
    }

    public static function getCacheLocation(): string
    {
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        if ($env !== false && $env !== '') {
            $dir = \rtrim($env, \DIRECTORY_SEPARATOR);
        } else {
            $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-' . \md5(\getcwd() ?: __DIR__);
        }

        if (! \is_dir($dir) && ! \mkdir($dir, 0777, true) && ! \is_dir($dir)) {
            throw new \RuntimeException("Cache directory '{$dir}' does not exist and could not be created.");
        }

        return $dir;
    }

}

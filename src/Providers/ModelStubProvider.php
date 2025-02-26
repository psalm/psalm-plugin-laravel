<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Exceptions\UnknownApplicationConfiguration;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Psalm\LaravelPlugin\Fakes\FakeModelsCommand;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function glob;
use function method_exists;
use function unlink;
use function is_array;

final class ModelStubProvider implements GeneratesStubs
{
    /** @var list<class-string<\Illuminate\Database\Eloquent\Model>> */
    private static array $model_classes = [];

    #[\Override]
    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        if (!method_exists($app, 'databasePath')) {
            throw new \RuntimeException('Unsupported Application type.');
        }

        $migrations_directory = $app->databasePath('migrations/');

        $project_analyzer = ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $schema_aggregator = new SchemaAggregator();

        $migrationFilePathnames = glob($migrations_directory . '*.php');
        if (! is_array($migrationFilePathnames)) {
            throw new UnknownApplicationConfiguration("No migration files found in {$migrations_directory} directory.");
        }

        foreach ($migrationFilePathnames as $file) {
            $schema_aggregator->addStatements($codebase->getStatementsForFile($file));
        }

        $fake_filesystem = new FakeFilesystem();

        $models_generator_command = new FakeModelsCommand(
            $fake_filesystem,
            $app->make(\Illuminate\Contracts\Config\Repository::class),
            $app->make(\Illuminate\View\Factory::class)
        );
        $models_generator_command->setSchemaAggregator($schema_aggregator);
        $models_generator_command->setLaravel($app);

        @unlink(self::getStubFileLocation());

        $fake_filesystem->setDestination(self::getStubFileLocation());

        $models_generator_command->run(
            new ArrayInput([
                '--nowrite' => true,
                '--reset' => true,
            ]),
            new NullOutput()
        );

        self::$model_classes = $models_generator_command->getModels();
    }

    #[\Override]
    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/models.stubphp';
    }

    /** @return list<class-string<\Illuminate\Database\Eloquent\Model>> */
    public static function getModelClasses(): array
    {
        return self::$model_classes;
    }
}

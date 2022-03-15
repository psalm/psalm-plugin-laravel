<?php

namespace Psalm\LaravelPlugin\Providers;

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function glob;
use function unlink;

final class ModelStubProvider implements GeneratesStubs
{
    /**
     * @var array<class-string>
     */
    private static $model_classes;

    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();
        $migrations_folder = $app->databasePath('migrations/');

        $project_analyzer = ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $schema_aggregator = new SchemaAggregator();

        foreach (glob($migrations_folder . '*.php') as $file) {
            //echo $file . "\n";
            $schema_aggregator->addStatements($codebase->getStatementsForFile($file));
        }

        $fake_filesystem = new FakeFilesystem();

        $models_generator_command = FakeModelsCommandProvider::getCommand(
            $fake_filesystem,
            $schema_aggregator
        );

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

    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/models.stubphp';
    }

    /**
     * @return array<class-string>
     */
    public static function getModelClasses(): array
    {
        return self::$model_classes;
    }
}

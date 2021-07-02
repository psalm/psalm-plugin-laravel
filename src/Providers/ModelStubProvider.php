<?php

namespace Psalm\LaravelPlugin\Providers;

use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Psalm\LaravelPlugin\Fakes\FakeModelsCommand;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use function glob;
use function dirname;
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
        $migrations_folder = dirname(__DIR__, 4) . '/database/migrations/';

        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $schema_aggregator = new SchemaAggregator();

        foreach (glob($migrations_folder . '*.php') as $file) {
            //echo $file . "\n";
            $schema_aggregator->addStatements($codebase->getStatementsForFile($file));
        }

        $fake_filesystem = new FakeFilesystem();

        $models_generator_command = new FakeModelsCommand(
            $fake_filesystem,
            $schema_aggregator
        );

        $models_generator_command->setLaravel($app);

        @unlink(self::getStubFileLocation());

        $fake_filesystem->setDestination(self::getStubFileLocation());

        $models_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([
                '--nowrite' => true
            ]),
            new \Symfony\Component\Console\Output\NullOutput()
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

<?php

namespace Psalm\LaravelPlugin\Providers;

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Psalm\LaravelPlugin\Fakes\FakeModelsCommand;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function glob;
use function method_exists;
use function unlink;

final class ModelStubProvider implements GeneratesStubs
{
    /**
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private static $model_classes;

    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        if (!method_exists($app, 'databasePath')) {
            throw new \RuntimeException('Unsupported Application type.');
        }

        /** @var string $migrations_directory */
        $migrations_directory = $app->databasePath('migrations/');

        $project_analyzer = ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $schema_aggregator = new SchemaAggregator();

        foreach (glob($migrations_directory . '*.php') as $file) {
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
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public static function getModelClasses(): array
    {
        return self::$model_classes;
    }
}

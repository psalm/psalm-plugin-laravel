<?php

namespace Psalm\LaravelPlugin\Providers;

use Barryvdh\LaravelIdeHelper\Console\GeneratorCommand;
use Illuminate\Config\Repository;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function unlink;
use function is_array;

final class FacadeStubProvider implements GeneratesStubs
{
    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        /** @var Repository $config */
        $config = $app['config'];

        // The \Eloquent mixin has less specific return types than our custom plugin can determine, so we unset it here
        // to not taint our analysis
        /** @var mixed $ideHelperExtra */
        $ideHelperExtra = $config->get('ide-helper.extra');
        if (is_array($ideHelperExtra) && isset($ideHelperExtra['Eloquent'])) {
            unset($ideHelperExtra['Eloquent']);
            $config->set('ide-helper.extra', $ideHelperExtra);
        }

        $fake_filesystem = new FakeFilesystem();

        /**
         * required a real one as ide-helper registers
         * uses its own views inside console commands the Psalm plugin uses
         * @var \Illuminate\View\Factory $viewFactory
         */
        $viewFactory = $app->make('view');

        // Register ide-helper views path
        $viewFactory->addNamespace('ide-helper', dirname((new \ReflectionClass(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class))->getFileName(), 2) . '/resources/views');

        $stubs_generator_command = new GeneratorCommand(
            $config,
            $fake_filesystem,
            $viewFactory
        );

        $stubs_generator_command->setLaravel($app);

        @unlink(self::getStubFileLocation());

        $fake_filesystem->setDestination(self::getStubFileLocation());

        $stubs_generator_command->run(
            new ArrayInput([]),
            new NullOutput()
        );
    }

    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/facades.stubphp';
    }
}

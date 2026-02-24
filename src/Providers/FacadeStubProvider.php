<?php

declare(strict_types=1);

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
    #[\Override]
    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        /** @var Repository $config */
        $config = $app['config'];

        // The \Eloquent mixin has less specific return types than our custom plugin can determine, so we unset it here
        // to not taint our analysis
        $ideHelperExtra = $config->get('ide-helper.extra');
        if (is_array($ideHelperExtra) && isset($ideHelperExtra['Eloquent'])) {
            unset($ideHelperExtra['Eloquent']);
            $config->set('ide-helper.extra', $ideHelperExtra);
        }

        $fake_filesystem = new FakeFilesystem();

        /**
         * @var \Illuminate\View\Factory $viewFactory
         */
        $viewFactory = $app->make('view');

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

    #[\Override]
    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/facades.stubphp';
    }
}

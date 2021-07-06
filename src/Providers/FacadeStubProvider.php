<?php

namespace Psalm\LaravelPlugin\Providers;

use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use function unlink;

final class FacadeStubProvider implements GeneratesStubs
{
    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // The \Eloquent mixin has less specific return types than our custom plugin can determine, so we unset it here
        // to not taint our analysis
        if ($ideHelperExtra = $config->get('ide-helper.extra')) {
            if (isset($ideHelperExtra['Eloquent'])) {
                unset($ideHelperExtra['Eloquent']);
                $config->set('ide-helper.extra', $ideHelperExtra);
            }
        }

        $fake_filesystem = new FakeFilesystem();

        $stubs_generator_command = new \Barryvdh\LaravelIdeHelper\Console\GeneratorCommand(
            $config,
            $fake_filesystem,
            ViewFactoryProvider::get(),
        );

        $stubs_generator_command->setLaravel($app);

        @unlink(self::getStubFileLocation());

        $fake_filesystem->setDestination(self::getStubFileLocation());

        $stubs_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
    }

    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/facades.stubphp';
    }
}

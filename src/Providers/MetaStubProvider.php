<?php

namespace Psalm\LaravelPlugin\Providers;

use Barryvdh\LaravelIdeHelper\Console\MetaCommand;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use Psalm\LaravelPlugin\Fakes\FakeMetaCommand;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;

final class MetaStubProvider implements GeneratesStubs
{

    public static function generateStubFile(): void
    {
        $app = ApplicationProvider::getApp();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $fake_filesystem = new FakeFilesystem();

        /** @psalm-suppress InvalidArgument */
        $meta_generator_command = new FakeMetaCommand(
            $fake_filesystem,
            ViewFactoryProvider::get(),
            $config
        );

        $meta_generator_command->setLaravel($app);

        @unlink(self::getStubFileLocation());

        $fake_filesystem->setDestination(self::getStubFileLocation());

        $meta_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
    }

    public static function getStubFileLocation(): string
    {
        return CacheDirectoryProvider::getCacheLocation() . '/meta.stubphp';
    }
}

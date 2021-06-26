<?php

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Psalm\LaravelPlugin\Fakes\FakeFilesystem;
use function dirname;

final class ViewFactoryProvider
{
    public static function get(): Factory
    {
        $service_helper_reflection = new \ReflectionClass(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);

        $file_path = $service_helper_reflection->getFileName();

        if (!$file_path) {
            throw new \UnexpectedValueException('Service helper should have a file path');
        }

        $resolver = new EngineResolver();
        $fake_filesystem = new FakeFilesystem();
        $resolver->register('php', function () use ($fake_filesystem) : PhpEngine {
            return new PhpEngine($fake_filesystem);
        });
        $finder = new FileViewFinder($fake_filesystem, [dirname($file_path) . '/../resources/views']);
        $factory = new Factory($resolver, $finder, new \Illuminate\Events\Dispatcher());
        $factory->addExtension('php', 'php');
        return $factory;
    }
}

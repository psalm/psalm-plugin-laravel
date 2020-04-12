<?php
namespace Psalm\LaravelPlugin;

use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

class Plugin extends AbstractPlugin implements PluginEntryPointInterface
{
    /**
     * Get and load ide provider for  Laravel Application container
     *
     * @param \Illuminate\Container\Container $app
     * @param string $ide_helper_provider
     * @return \Illuminate\Contracts\Foundation\Application|\Laravel\Lumen\Application|\Illuminate\Container\Container
     */
    protected function loadIdeProvider($app, $ide_helper_provider)
    {
        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            /** @var \Illuminate\Contracts\Http\Kernel $kernel */
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            // If we're running a Laravel container, let's see if we need to register the IDE helper if it isn't
            // already. If we don't do this, the plugin will crash out because the IDE helper doesn't have configs
            // it bootstraps present in the app container.

            if (!$app->getProvider($ide_helper_provider)) {
                $app->register($ide_helper_provider);
            }
        }
        return $app;
    }
}

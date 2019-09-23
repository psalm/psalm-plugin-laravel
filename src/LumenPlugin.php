<?php
namespace Psalm\LaravelPlugin;

class LumenPlugin extends AbstractPlugin implements PluginEntryPointInterface
{
    /**
     * Get and load ide provider for Lumen Application container
     *
     * @param \Illuminate\Container\Container $app
     * @param string $ide_helper_provider
     * @return \Illuminate\Contracts\Foundation\Application|\Laravel\Lumen\Application|\Illuminate\Container\Container
     */
    public function loadIdeProvider($app, $ide_helper_provider){
        if ($app instanceof \Laravel\Lumen\Application) {
            /** @var \Illuminate\Contracts\Http\Kernel $kernel */
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $app->register($ide_helper_provider);
        }
        return $app;
    }
}
<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Orchestra\Testbench\Concerns\CreatesApplication;
use function file_exists;
use function getcwd;

final class ApplicationHelper
{
    use CreatesApplication;

    /**
     * @return \Illuminate\Foundation\Application|\Laravel\Lumen\Application
     */
    public static function bootApp()
    {
        if (file_exists($applicationPath = __DIR__.'/../../../../bootstrap/app.php')) { // Applications
            $app = require $applicationPath;
        } elseif (file_exists($applicationPath = getcwd().'/bootstrap/app.php')) { // Local Dev
            $app = require $applicationPath;
        } else { // Packages
            $app = (new static)->createApplication();
        }

        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        } elseif ($app instanceof \Laravel\Lumen\Application) {
            $app->boot();
        }

        $app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);

        return $app;
    }

    /**
     * Resolve application bootstrapper.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return void
     */
    protected function resolveApplicationBootstrappers($app)
    {
        // we want to keep the default psalm exception handler, otherwise the Laravel one will always return exit codes
        // of 0
        //$app->make('Illuminate\Foundation\Bootstrap\HandleExceptions')->bootstrap($app);
        $app->make('Illuminate\Foundation\Bootstrap\RegisterFacades')->bootstrap($app);
        $app->make('Illuminate\Foundation\Bootstrap\SetRequestForConsole')->bootstrap($app);
        $app->make('Illuminate\Foundation\Bootstrap\RegisterProviders')->bootstrap($app);

        $this->getEnvironmentSetUp($app);

        $app->make('Illuminate\Foundation\Bootstrap\BootProviders')->bootstrap($app);

        foreach ($this->getPackageBootstrappers($app) as $bootstrap) {
            $app->make($bootstrap)->bootstrap($app);
        }

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        $app['router']->getRoutes()->refreshNameLookups();

        /**
         * @psalm-suppress MissingClosureParamType
         */
        $app->resolving('url', static function ($url, $app) {
            $app['router']->getRoutes()->refreshNameLookups();
        });
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');
    }
}

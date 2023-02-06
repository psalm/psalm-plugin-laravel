<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;
use Laravel\Lumen\Application as LumenApplication;
use Orchestra\Testbench\Concerns\CreatesApplication;

use function file_exists;
use function get_class;
use function getcwd;

final class ApplicationProvider
{
    use CreatesApplication;

    /**
     * @var LaravelApplication|LumenApplication|null
     */
    private static $app;

    public static function bootApp(): void
    {
        $app = self::getApp();

        if ($app instanceof Application) {
            /** @var \Illuminate\Contracts\Console\Kernel $consoleApp */
            $consoleApp = $app->make(Kernel::class);
            $consoleApp->bootstrap();
        } else {
            $app->boot();
        }

        $app->register(IdeHelperServiceProvider::class);
    }

    public static function getApp(): LaravelApplication | LumenApplication
    {
        if (self::$app) {
            return self::$app;
        }

        if (file_exists($applicationPath = __DIR__ . '/../../../../bootstrap/app.php')) { // plugin installed to vendor
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
            if (! $app instanceof LaravelApplication && ! $app instanceof LumenApplication) {
                throw new \RuntimeException('Could not instantiate Application: unknown path.');
            }
        } elseif (file_exists($applicationPath = getcwd() . '/bootstrap/app.php')) { // Local Dev
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
            if (! $app instanceof LaravelApplication && ! $app instanceof LumenApplication) {
                throw new \RuntimeException('Could not instantiate Application: unknown path.');
            }
        } else { // Packages
            $app = (new self())->createApplication(); // Orchestra\Testbench
        }

        self::$app = $app;

        return $app;
    }

    /**
     * @psalm-return class-string<\Illuminate\Foundation\Application|\Laravel\Lumen\Application>
     */
    public static function getAppFullyQualifiedClassName(): string
    {
        return get_class(self::getApp());
    }

    /**
     * Overrides {@see \Orchestra\Testbench\Concerns\CreatesApplication::resolveApplicationBootstrappers}
     * Resolve application bootstrapper.
     *
     * @param LaravelApplication $app
     *
     * @return void
     */
    protected function resolveApplicationBootstrappers($app)
    {
        // we want to keep the default psalm exception handler, otherwise the Laravel one will always return exit codes
        // of 0
        //$app->make('Illuminate\Foundation\Bootstrap\HandleExceptions')->bootstrap($app);
        /** @psalm-suppress MixedMethodCall */
        $app->make('Illuminate\Foundation\Bootstrap\RegisterFacades')->bootstrap($app);
        /** @psalm-suppress MixedMethodCall */
        $app->make('Illuminate\Foundation\Bootstrap\SetRequestForConsole')->bootstrap($app);
        /** @psalm-suppress MixedMethodCall */
        $app->make('Illuminate\Foundation\Bootstrap\RegisterProviders')->bootstrap($app);

        $this->getEnvironmentSetUp($app);

        /** @psalm-suppress MixedMethodCall */
        $app->make('Illuminate\Foundation\Bootstrap\BootProviders')->bootstrap($app);

        foreach ($this->getPackageBootstrappers($app) as $bootstrap) {
            /** @psalm-suppress MixedMethodCall */
            $app->make($bootstrap)->bootstrap($app);
        }

        /** @psalm-suppress MixedMethodCall */
        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        /** @var \Illuminate\Routing\Router $router */
        $router = $app['router'];
        $router->getRoutes()->refreshNameLookups();

        /**
         * @psalm-suppress MissingClosureParamType
         * @psalm-suppress UnusedClosureParam
         */
        $app->resolving('url', static function ($url, $app) use ($router) {
            $router->getRoutes()->refreshNameLookups();
        });
    }

    /**
     * @param LaravelApplication $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');

        // in testing, we want ide-helper to load our test models. Unfortunately this has to be a relative path, with
        // the base path being inside of orchestra/testbench-core/laravel

        $config->set('ide-helper.model_locations', [
            '../../../../tests/Application/app/Models',
        ]);
    }
}

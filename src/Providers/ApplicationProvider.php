<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\AliasLoader;
use Laravel\Lumen\Application as LumenApplication;
use Orchestra\Testbench\Concerns\CreatesApplication;

use function define;
use function defined;
use function dirname;
use function file_exists;
use function get_class;
use function getcwd;
use function microtime;

final class ApplicationProvider
{
    use CreatesApplication;

    /** @var LaravelApplication|null */
    private static $app = null;

    public static function bootApp(): void
    {
        $app = self::getApp();

        /** @var \Illuminate\Contracts\Console\Kernel $consoleApp */
        $consoleApp = $app->make(Kernel::class);
        // @todo do not bootstrap \Illuminate\Foundation\Bootstrap\HandleExceptions
        $consoleApp->bootstrap();

        self::bootAppRegister($app);
        self::bootAppAlias();
    }

    protected static function bootAppRegister(LaravelApplication $app): void
    {
        $app->register(IdeHelperServiceProvider::class);
    }

    protected static function bootAppAlias(): void
    {
        AliasLoader::getInstance()->alias('Eloquent', Model::class);
    }

    public static function getApp(): LaravelApplication
    {
        if (self::$app instanceof \Illuminate\Container\Container) {
            return self::$app;
        }

        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        if (file_exists($applicationPath = getcwd() . '/bootstrap/app.php')) { // Applications and Local Dev
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
        } elseif (file_exists($applicationPath = dirname(__DIR__, 5) . '/bootstrap/app.php')) { // plugin installed to vendor
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
        } else { // Laravel Packages
            $app = (new self())->createApplication(); // Orchestra\Testbench
        }

        if (! $app instanceof LaravelApplication) {
            throw new \RuntimeException('Could not find Laravel bootstrap file.');
        }

        self::$app = $app;

        return $app;
    }

    /**
     * @psalm-return class-string<\Illuminate\Foundation\Application>
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

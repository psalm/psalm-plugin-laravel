<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\Foundation\Application as LaravelApplication;
use Orchestra\Testbench\Concerns\CreatesApplication;

use function define;
use function defined;
use function dirname;
use function file_exists;
use function getcwd;
use function microtime;

final class ApplicationProvider
{
    use CreatesApplication;

    private static ?\Illuminate\Foundation\Application $app = null;

    public static function bootApp(): void
    {
        self::getApp();
    }

    private static bool $booted = false;

    public static function getApp(): LaravelApplication
    {
        if (self::$app instanceof Container) {
            return self::$app;
        }

        // Psalm's ErrorHandler converts ALL PHP warnings/notices to RuntimeException.
        // Laravel's bootstrap process emits warnings during service provider loading
        // (e.g., deprecated features, missing extensions). We must suppress Psalm's
        // error handler during boot to prevent these from crashing the plugin.
        return self::withDefaultErrorHandler(static function (): LaravelApplication {
            return (new self())->doGetApp();
        });
    }

    private function doGetApp(): LaravelApplication
    {
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        if (file_exists($applicationPath = (getcwd() ?: '.') . '/bootstrap/app.php')) { // Applications and Local Dev
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
            assert($app instanceof LaravelApplication, 'Could not find Laravel bootstrap file.');
        } elseif (file_exists($applicationPath = dirname(__DIR__, 5) . '/bootstrap/app.php')) { // plugin installed to vendor
            /** @psalm-suppress MixedAssignment */
            $app = require $applicationPath;
            assert($app instanceof LaravelApplication, 'Could not find Laravel bootstrap file.');
        } else { // Laravel Packages
            /** @psalm-suppress InternalMethod */
            $app = (new self())->createApplication(); // Orchestra\Testbench (e.g., test:type command)
        }

        self::$app = $app;

        // Initialize view system first
        if (!$app->bound('view')) {
            $filesystem = new \Illuminate\Filesystem\Filesystem();
            $viewFinder = new FileViewFinder($filesystem, []);
            $engineResolver = new EngineResolver();
            $engineResolver->register('php', fn(): PhpEngine => new PhpEngine($filesystem));
            /** @var \Illuminate\Contracts\Events\Dispatcher $events */
            $events = $app['events'];
            $app->singleton('view', fn(): \Illuminate\View\Factory => new Factory($engineResolver, $viewFinder, $events));
        }

        if (!self::$booted) {
            // Bootstrap console app
            $consoleApp = $app->make(Kernel::class);
            $app->bind('Illuminate\Foundation\Bootstrap\HandleExceptions', function (): object {
                return new class {
                    /** @psalm-mutation-free */
                    public function bootstrap(): void {}
                };
            });
            $consoleApp->bootstrap();

            self::$booted = true;
        }

        return $app;
    }

    /**
     * Run a callback with PHP's default error handler instead of Psalm's.
     *
     * Psalm's ErrorHandler converts ALL PHP warnings to RuntimeException, which
     * crashes Laravel's bootstrap process. This temporarily restores the default
     * error handler, runs the callback, then restores whatever handler was active.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function withDefaultErrorHandler(callable $callback): mixed
    {
        // If Psalm's ErrorHandler is loaded, use its built-in suppression mechanism
        if (\class_exists(\Psalm\Internal\ErrorHandler::class, false)) {
            return \Psalm\Internal\ErrorHandler::runWithExceptionsSuppressed($callback);
        }

        // Fallback: install a passthrough handler that lets PHP handle errors normally
        \set_error_handler(static function (): bool {
            return false;
        });

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * @psalm-return class-string<\Illuminate\Foundation\Application>
     */
    public static function getAppFullyQualifiedClassName(): string
    {
        return self::getApp()::class;
    }

    /**
     * Overrides {@see \Orchestra\Testbench\Concerns\CreatesApplication::resolveApplicationBootstrappers}
     * Resolve application bootstrapper.
     */
    protected function resolveApplicationBootstrappers(LaravelApplication $app): void
    {
        // we want to keep the default psalm exception handler, otherwise the Laravel one will always return exit codes
        // of 0
        //$app->make('Illuminate\Foundation\Bootstrap\HandleExceptions')->bootstrap($app);
        $app->make(\Illuminate\Foundation\Bootstrap\RegisterFacades::class)->bootstrap($app);
        $app->make(\Illuminate\Foundation\Bootstrap\SetRequestForConsole::class)->bootstrap($app);
        $app->make(\Illuminate\Foundation\Bootstrap\RegisterProviders::class)->bootstrap($app);

        $this->getEnvironmentSetUp($app);

        $app->make(\Illuminate\Foundation\Bootstrap\BootProviders::class)->bootstrap($app);

        foreach ($this->getPackageBootstrappers($app) as $bootstrap) {
            /** @var \Illuminate\Foundation\Bootstrap\BootProviders $bootstrapper */
            $bootstrapper = $app->make($bootstrap);
            $bootstrapper->bootstrap($app);
        }

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        /** @var \Illuminate\Routing\Router $router */
        $router = $app['router'];
        $router->getRoutes()->refreshNameLookups();

        /**
         * @psalm-suppress MissingClosureParamType
         * @psalm-suppress UnusedClosureParam
         */
        $app->resolving('url', static function ($url, $app) use ($router): void {
            $router->getRoutes()->refreshNameLookups();
        });
    }

    protected function getEnvironmentSetUp(LaravelApplication $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');

        // ModelDiscoveryProvider falls back to ide-helper.model_locations for model scanning.
        // In testing, this path is relative to orchestra/testbench-core/laravel base path.
        $config->set('ide-helper.model_locations', [
            '../../../../tests/Application/app/Models',
        ]);
    }
}

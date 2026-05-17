<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Orchestra\Testbench\Concerns\CreatesApplication;

final class ApplicationProvider
{
    use CreatesApplication;

    private static ?\Illuminate\Foundation\Application $app = null;

    /**
     * Records which {@see doGetApp()} branch resolved the Laravel app.
     *
     * Values: 'bootstrap' | 'testbench_fallback'. The two `bootstrap/app.php`
     * lookups (cwd-relative and vendor-parent-relative) collapse into one mode
     * since {@see $bootPath} already discloses *which* file was loaded.
     * Kept as a plain string (not an enum) since it's only read by
     * `bin/psalm-laravel diagnose` and never compared against typed cases.
     *
     * @psalm-var 'bootstrap'|'testbench_fallback'|null
     */
    private static ?string $bootMode = null;

    private static ?string $bootPath = null;

    /**
     * Set when {@see doGetApp()} successfully `require`d a `bootstrap/app.php`
     * but the subsequent `$consoleApp->bootstrap()` threw — typically because
     * one of the user project's `config/*.php` files fatals during evaluation
     * (e.g. `parse_url(env('UNSET_VAR'))` returning null on PHP 8.1+).
     *
     * Plugin continues with a partially-loaded app: `config` binding still
     * exists (created before LoadConfiguration iterates files) but later
     * bootstrappers (RegisterFacades, RegisterProviders, BootProviders) never
     * ran. Handlers tolerate partial state — same swallow semantics as
     * {@see Plugin::__invoke}.
     */
    private static ?\Throwable $bootstrapError = null;

    public static function bootApp(): void
    {
        self::getApp();
    }

    /**
     * Throwable raised during eager Laravel bootstrap (LoadConfiguration etc.).
     * Null when no bootstrap was attempted yet, or when bootstrap succeeded.
     *
     * @psalm-external-mutation-free
     */
    public static function getBootstrapError(): ?\Throwable
    {
        return self::$bootstrapError;
    }

    private static bool $booted = false;

    /**
     * Which {@see doGetApp()} branch resolved the Laravel app.
     *
     * Null until the app has been booted via {@see bootApp()} or {@see getApp()}.
     * Read by `bin/psalm-laravel diagnose` to surface the #766 silent-Testbench-fallback case.
     *
     * @return 'bootstrap'|'testbench_fallback'|null
     *
     * @psalm-external-mutation-free
     */
    public static function getBootMode(): ?string
    {
        return self::$bootMode;
    }

    /**
     * Path actually used to bootstrap the Laravel app — either the resolved `bootstrap/app.php` (bootstrap mode)
     * or the Testbench skeleton root (testbench_fallback).
     *
     * Null until the app has been booted.
     *
     * @psalm-external-mutation-free
     */
    public static function getBootPath(): ?string
    {
        return self::$bootPath;
    }

    public static function getApp(): LaravelApplication
    {
        if (self::$app instanceof Container) {
            return self::$app;
        }

        // Psalm's ErrorHandler converts PHP warnings/notices to RuntimeException.
        // Laravel's bootstrap process may emit warnings during service provider loading
        // (e.g., deprecated features, missing extensions). We suppress exception-throwing
        // during boot to prevent these from crashing the plugin.
        return self::withErrorExceptionsSuppressed(static function (): LaravelApplication {
            return (new self())->doGetApp();
        });
    }

    /**
     * Bootstrap the Laravel application — extracted from getApp() to run
     * inside the error-handler suppression wrapper.
     */
    private function doGetApp(): LaravelApplication
    {
        if (! \defined('LARAVEL_START')) {
            \define('LARAVEL_START', \microtime(true));
        }

        // Resolution order:
        //   1. cwd-relative bootstrap/app.php — Applications and local dev (Psalm run from project root).
        //   2. vendor-parent-relative bootstrap/app.php — plugin installed into a project's vendor/.
        //   3. Orchestra Testbench skeleton — Laravel packages with no host app (e.g. test:type).
        $app = $this->bootFromBootstrapFile((\getcwd() ?: '.') . '/bootstrap/app.php')
            ?? $this->bootFromBootstrapFile(\dirname(__DIR__, 5) . '/bootstrap/app.php')
            ?? $this->bootFromTestbench();

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
            // Bootstrap console app — runs Laravel's standard bootstrappers
            // (LoadEnvironmentVariables, LoadConfiguration, RegisterFacades,
            // RegisterProviders, BootProviders). Required because handlers
            // read app('config'), facades, and provider-registered bindings.
            $consoleApp = $app->make(Kernel::class);
            $app->bind('Illuminate\Foundation\Bootstrap\HandleExceptions', function (): object {
                return new class {
                    /** @psalm-mutation-free */
                    public function bootstrap(): void {}
                };
            });

            // Tolerate partial bootstrap. One bad config file (`parse_url(env('UNSET'))`
            // throwing TypeError on PHP 8.1+ is a common pattern) would otherwise
            // abort the entire bootstrap chain and disable the plugin for the run.
            // The 'config' binding is created BEFORE LoadConfiguration iterates files,
            // so handlers reading config still see whatever loaded prior to the throw.
            try {
                $consoleApp->bootstrap();
            } catch (\Throwable $bootstrapError) {
                self::$bootstrapError = $bootstrapError;
            }

            self::$booted = true;
        }

        return $app;
    }

    /**
     * Require a `bootstrap/app.php` from $path and return its Application, or
     * null if the file does not exist. Records `bootMode = 'bootstrap'` and the
     * resolved path as a side effect on success.
     */
    private function bootFromBootstrapFile(string $path): ?LaravelApplication
    {
        if (!\file_exists($path)) {
            return null;
        }

        /** @psalm-suppress MixedAssignment */
        $app = require $path;
        assert($app instanceof LaravelApplication, 'bootstrap/app.php did not return an Application instance: ' . $path);

        self::$bootMode = 'bootstrap';
        self::$bootPath = $path;

        return $app;
    }

    /**
     * Fall back to Orchestra Testbench when no `bootstrap/app.php` is reachable
     * (Laravel package analysis, plugin self-test).
     */
    private function bootFromTestbench(): LaravelApplication
    {
        /** @psalm-suppress InternalMethod */
        $app = (new self())->createApplication();

        $this->retargetConfigPathAtProjectRoot($app);

        self::$bootMode = 'testbench_fallback';
        self::$bootPath = $app->basePath();

        return $app;
    }

    /**
     * Re-point the booted Laravel app's `config_path()` at the project root.
     *
     * Testbench's `createApplication()` anchors the booted app at its bundled
     * `vendor/orchestra/testbench-core/laravel` skeleton. That works for the
     * *infrastructure* paths (`bootstrap/cache/`, `storage/`) since the skeleton
     * ships writable scaffolding, but it is wrong for `config_path()`: the
     * NoEnvOutsideConfig rule resolves the skeleton's `vendor/orchestra/.../config`
     * dir, which never matches the package's own `config/*.php` files and every
     * `env()` call there gets reported (issue #940).
     *
     * We retarget *only* the config path on purpose. Sibling helpers
     * (`database_path()` for migration discovery, `lang_path()` for translation
     * lookups, `resource_path()` for view discovery) drive other handlers that
     * may not handle a real-but-empty project tree well — leaving them at the
     * Testbench skeleton keeps that behaviour unchanged.
     *
     * Resolution order for the project root:
     *   1. Testbench's documented escape hatch — `$_ENV['APP_BASE_PATH']` or
     *      `$_ENV['TESTBENCH_APP_BASE_PATH']`. Lets users pin a specific anchor
     *      (monorepos, sub-directory Psalm runs).
     *   2. `getcwd()` if it contains a `composer.json`. The composer manifest is
     *      a strong signal that cwd IS the project we want Laravel to "see";
     *      Psalm anchors at this directory by default. This handles every
     *      conventional Laravel package without configuration.
     *   3. No anchor identified — leave the Testbench skeleton path in place
     *      (the previous behaviour). Better to keep working defaults than point
     *      at a wrong path that wasn't requested.
     *
     * Only branch 3 of {@see self::doGetApp()} calls this — for projects with a
     * real `bootstrap/app.php`, that file's Application instance is used verbatim
     * and Testbench is never consulted.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/940
     */
    private function retargetConfigPathAtProjectRoot(LaravelApplication $app): void
    {
        $envOverride = $this->readEnvOverride('APP_BASE_PATH')
            ?? $this->readEnvOverride('TESTBENCH_APP_BASE_PATH');

        if ($envOverride !== null) {
            $projectRoot = $envOverride;
        } else {
            $cwd = \getcwd();

            if (!\is_string($cwd) || !\is_file($cwd . \DIRECTORY_SEPARATOR . 'composer.json')) {
                return;
            }

            $projectRoot = $cwd;
        }

        $app->useConfigPath($projectRoot . \DIRECTORY_SEPARATOR . 'config');
    }

    /**
     * Read an environment variable across the three PHP surfaces.
     *
     * `$_ENV` alone is unreliable: the `variables_order` ini setting may omit `E`,
     * leaving `$_ENV` empty even when the value was passed to the process. CGI/FPM
     * deployments commonly route through `$_SERVER`; CLI invocations via `env VAR=...
     * php ...` route through `getenv()`. Testbench's own helper reads only `$_ENV`,
     * which is the documented escape hatch but not the most portable one — we widen
     * the check so the override actually takes effect across runtime configurations.
     */
    private function readEnvOverride(string $name): ?string
    {
        $candidates = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, \getenv($name)];

        foreach ($candidates as $value) {
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Run a callback with Psalm's error-to-exception promotion disabled.
     *
     * When Psalm's ErrorHandler is loaded, it uses `runWithExceptionsSuppressed()`
     * which toggles the `$exceptions_enabled` flag on Psalm's existing handler.
     * When Psalm's ErrorHandler is not loaded (e.g., testing), the fallback installs
     * a passthrough handler that delegates to PHP's default error handling.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function withErrorExceptionsSuppressed(callable $callback): mixed
    {
        if (\class_exists(\Psalm\Internal\ErrorHandler::class, false)) {
            return \Psalm\Internal\ErrorHandler::runWithExceptionsSuppressed($callback);
        }

        // Fallback: install a passthrough handler that delegates to PHP's default behavior
        \set_error_handler(static function (int $_errno, string $_errstr): bool {
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

        // Register a token-driver guard so Auth::guard('api') narrows to TokenGuard in type tests.
        // The testbench default auth.php only ships a 'web' (session) guard; without this the
        // AuthConfigAnalyzer cannot resolve the driver and falls back to the stub's Guard interface.
        $config->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
        ]);
    }
}

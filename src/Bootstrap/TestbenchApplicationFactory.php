<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Bootstrap;

use Illuminate\Foundation\Application as LaravelApplication;
use Orchestra\Testbench\Concerns\CreatesApplication;

/**
 * Boots the Orchestra Testbench skeleton for {@see ApplicationProvider}'s fallback. A class of its own, so that only
 * projects without a `bootstrap/app.php` need Testbench installed.
 *
 * @internal
 */
final class TestbenchApplicationFactory
{
    use CreatesApplication;

    public function create(): LaravelApplication
    {
        /** @psalm-suppress InternalMethod */
        return $this->createApplication();
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
        // Backfill app.key BEFORE testbench's BootProviders runs (called next in
        // resolveApplicationBootstrappers) so any provider that reads config('app.key')
        // during boot sees the dummy. ApplicationProvider::doGetApp() also calls ensureAppKey() after the
        // console bootstrap completes — that covers the bootstrap-file branch, which
        // never enters this method. Single constant lives in ensureAppKey().
        ApplicationProvider::ensureAppKey($app);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // Register a token-driver guard so Auth::guard('api') narrows to TokenGuard in type tests.
        // The testbench default auth.php only ships a 'web' (session) guard; without this the
        // AuthConfigAnalyzer cannot resolve the driver and falls back to the stub's Guard interface.
        $config->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use ViewContractFixture\Composers\LiteralComposer;

final class FixtureViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Ran at boot, so its keys were read: `composer-provider` keeps the per-key check.
        View::composer('composer-provider', LiteralComposer::class);
        View::composer('composer-mixed', LiteralComposer::class);

        // Runs later, on a request: `runtime-closure-provider` is exempt as a whole.
        $this->app->terminating(static function (): void {
            View::composer('runtime-closure-provider', static fn(): null => null);
        });
    }
}

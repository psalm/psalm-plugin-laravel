<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SubscriptionClient;
use Illuminate\Support\ServiceProvider;

/**
 * Stand-in for the kind of vendor provider that issue #942 calls out: a
 * `ServiceProvider::register()` body that binds an accessor string to a concrete
 * service class. The provider itself is never booted inside the plugin's
 * Testbench app — its bindings reach the plugin only via the static AST harvest
 * performed by
 * {@see \Psalm\LaravelPlugin\Providers\BootTimeProviderHarvester}.
 */
final class SubscriptionServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('subscription', SubscriptionClient::class);
    }
}

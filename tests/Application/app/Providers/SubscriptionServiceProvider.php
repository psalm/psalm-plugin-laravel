<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SubscriptionClient;
use Illuminate\Support\ServiceProvider;

/**
 * Stand-in for the kind of vendor provider that issue #942 calls out: a
 * `ServiceProvider::register()` body that binds an accessor string to a concrete
 * service class.
 *
 * Seeded for type-test runs by `tests/Type/macro-fixtures.php`, which loads
 * Psalm's plugin application provider and manually invokes
 * `$app->register(SubscriptionServiceProvider::class)`. After that call,
 * `'subscription'` is in `$app->getBindings()` and {@see \Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider::init()}
 * harvests it into the snapshot consulted by {@see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler}.
 *
 * In production this seeding happens automatically: composer auto-discovery
 * registers vendor providers via `Illuminate\Foundation\PackageManifest`, which
 * the plugin retargets at the project root inside
 * {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider::retargetPackageManifestAtProjectRoot()}.
 * The plugin's own test fixtures are not a composer package, so an explicit
 * `$app->register()` stands in for the discovery step.
 */
final class SubscriptionServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('subscription', SubscriptionClient::class);
    }
}

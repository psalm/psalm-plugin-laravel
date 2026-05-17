--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\Subscription;
use App\Services\SubscriptionClient;

/**
 * The `'subscription'` accessor is bound to `SubscriptionClient` by
 * `App\Providers\SubscriptionServiceProvider::register()`. That provider's
 * binding reaches the plugin via `ContainerBindingMapProvider`, which snapshots
 * `$app->getBindings()` after the booted Testbench application's
 * `register()` cycle. The fixture provider is seeded from
 * `tests/Type/macro-fixtures.php` (the file Psalm loads via the `autoloader`
 * attribute in `psalm.xml`). In production, vendor providers reach the same
 * map via composer auto-discovery (Illuminate's `PackageManifest`, retargeted
 * at the project root by `ApplicationProvider`).
 *
 * `Subscription`'s accessor is a string alias, not a class-string, so the
 * runtime `Facade::getFacadeRoot()` probe used by `AppFacadeRegistrationHandler`
 * cannot resolve it on its own when the provider's `register()` is bound via
 * a factory closure with un-bound deps (the imdhemy/laravel-in-app-purchases
 * shape cited in issue #942). The binding map snapshot covers that case.
 *
 * Methods on `SubscriptionClient` must be forwarded to the facade without an
 * `@method` catalogue and with the correct return type.
 */
function test_facade_resolves_method_via_container_binding_map(): SubscriptionClient
{
    /** @psalm-check-type-exact $client = SubscriptionClient */
    $client = Subscription::googlePlay();

    return $client;
}
?>
--EXPECT--

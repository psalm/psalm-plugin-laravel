--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\Subscription;
use App\Services\SubscriptionClient;

/**
 * The `'subscription'` accessor is bound to `SubscriptionClient` by
 * `App\Providers\SubscriptionServiceProvider::register()`. That provider's
 * bindings reach the plugin via the static AST harvest in
 * `BootTimeProviderHarvester`, which is seeded for type-test runs from
 * `tests/Type/macro-fixtures.php` (the file Psalm loads via the `autoloader`
 * attribute in `psalm.xml`). In production, the same harvester reads composer
 * `extra.laravel.providers[]` and `bootstrap/providers.php`.
 *
 * `Subscription`'s accessor is a string alias, not a class-string, so the
 * runtime `Facade::getFacadeRoot()` probe used by `AppFacadeRegistrationHandler`
 * throws `BindingResolutionException` inside the plugin's Testbench app — issue
 * #942 — and resolution falls to the binding map.
 *
 * Methods on `SubscriptionClient` must be forwarded to the facade without an
 * `@method` catalogue and with the correct return type.
 */
function test_facade_resolves_method_via_static_binding_map(): SubscriptionClient
{
    /** @psalm-check-type-exact $client = SubscriptionClient */
    $client = Subscription::googlePlay('token');

    return $client;
}

/**
 * `app('subscription')` is the issue #766 path: `ContainerResolver` queries the
 * Testbench app's `make()`, which would throw because the provider is not booted.
 * With the static binding map seeded by the same harvester, the resolver returns
 * the concrete `SubscriptionClient` type instead of cascading into `mixed`.
 */
function test_app_helper_resolves_alias_via_static_binding_map(): SubscriptionClient
{
    /** @psalm-check-type-exact $client = SubscriptionClient */
    $client = \app('subscription');

    return $client;
}
?>
--EXPECT--

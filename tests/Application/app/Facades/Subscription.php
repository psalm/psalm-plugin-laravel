<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Test fixture for issue #942. The accessor is the string alias `'subscription'`,
 * bound by {@see \App\Providers\SubscriptionServiceProvider::register()} — not by
 * a class-string accessor, so the runtime `Facade::getFacadeRoot()` probe alone
 * would throw `BindingResolutionException` when the provider has not run.
 *
 * Resolution must succeed via the binding map snapshot taken at plugin init by
 * {@see \Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider}, which reads
 * the booted container's `$bindings` after each provider's `register()` has run.
 *
 * No `@method` catalogue here on purpose — we want to assert that the snapshot
 * supplies the underlying class so {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler}
 * can forward real method signatures from the service class.
 */
class Subscription extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'subscription';
    }
}

<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Test fixture for issue #942. The accessor is the string alias `'subscription'`,
 * bound by {@see \App\Providers\SubscriptionServiceProvider::register()} — not by
 * a class-string, so the runtime `Facade::getFacadeRoot()` probe fails inside the
 * plugin's Testbench app. Resolution must succeed via the statically-harvested
 * binding map maintained by
 * {@see \Psalm\LaravelPlugin\Providers\BootTimeProviderHarvester}.
 *
 * No `@method` catalogue here on purpose — we want to assert that the harvester
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

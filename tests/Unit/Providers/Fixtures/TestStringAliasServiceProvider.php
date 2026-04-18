<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Mirrors the yajra/laravel-datatables pattern: a package that registers its
 * bindings under string keys (`datatables.request`, `datatables.config`, ...).
 *
 * `PackageProviderRegistrar` must register this provider into the Testbench
 * app so `ContainerResolver` can resolve `app('test.string.binding')` to the
 * bound concrete class. See issue #766.
 */
final class TestStringAliasServiceProvider extends ServiceProvider
{
    public const STRING_KEY = 'test.string.binding.issue766';

    public function register(): void
    {
        $this->app->singleton(
            self::STRING_KEY,
            static fn(): TestStringAliasTarget => new TestStringAliasTarget(),
        );
    }
}

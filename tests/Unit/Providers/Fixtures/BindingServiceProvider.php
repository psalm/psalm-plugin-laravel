<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Fixture provider whose `register()` binds a sentinel into the container.
 * Used alongside {@see ThrowingServiceProvider} to verify the per-provider
 * try/catch isolation contract in
 * {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider::registerDiscoveredVendorProviders()}:
 * a throwing earlier provider must not stop this binding from being registered.
 */
final class BindingServiceProvider extends ServiceProvider
{
    public const string BINDING_KEY = 'psalm-plugin-laravel.tests.fixture.binding';

    public const string BOUND_VALUE = 'present';

    #[\Override]
    public function register(): void
    {
        $this->app->bind(self::BINDING_KEY, fn(): string => self::BOUND_VALUE);
    }
}

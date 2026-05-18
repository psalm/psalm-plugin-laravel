<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Fixture provider whose `register()` throws. Used to verify that
 * {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider::registerDiscoveredVendorProviders()}
 * isolates per-provider failures so subsequent providers still register.
 */
final class ThrowingServiceProvider extends ServiceProvider
{
    public const string FAILURE_MESSAGE = 'Tests\\Psalm\\LaravelPlugin: forced register() failure';

    #[\Override]
    public function register(): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}

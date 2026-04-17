<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Simulates a package provider whose `register()` throws — for instance, a
 * provider that eagerly resolves an optional dependency not installed in the
 * analysis environment. {@see PackageProviderRegistrar} must swallow the
 * exception so a single bad provider doesn't disable analysis for the run.
 */
final class BrokenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new \RuntimeException('intentional failure in BrokenServiceProvider::register()');
    }
}

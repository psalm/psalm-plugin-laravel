<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class TraitHostedService {}

/**
 * A trait body is edited once however many classes use it, and another consumer's `$app` can be a container
 * whose `scoped()` takes other parameters. Reported, never renamed.
 */
trait RegistersTraitHostedService
{
    public function register(): void
    {
        $this->app->singleton(abstract: TraitHostedService::class, concrete: fn($app) => new TraitHostedService($app->make(Request::class)));
    }
}

final class TraitHosted extends ServiceProvider
{
    use RegistersTraitHostedService;
}

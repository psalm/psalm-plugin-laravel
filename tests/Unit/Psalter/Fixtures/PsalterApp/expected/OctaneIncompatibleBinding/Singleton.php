<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class SingletonService
{
}

final class Singleton extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SingletonService::class, fn ($app) => new SingletonService($app->make(Request::class)));
    }
}

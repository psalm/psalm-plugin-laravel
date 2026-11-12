<?php

declare(strict_types=1);

namespace PsalterFixture\Cases;

use Illuminate\Support\ServiceProvider;

final class SafeSingletonService {}

final class SafeSingleton extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SafeSingletonService::class, fn($app) => new SafeSingletonService());
    }
}

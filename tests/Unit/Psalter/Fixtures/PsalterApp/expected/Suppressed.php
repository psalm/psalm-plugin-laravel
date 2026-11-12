<?php

declare(strict_types=1);

namespace PsalterFixture\Cases;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class SuppressedService {}

final class Suppressed extends ServiceProvider
{
    /** @psalm-suppress OctaneIncompatibleBinding */
    public function register(): void
    {
        $this->app->singleton(SuppressedService::class, fn($app) => new SuppressedService($app->make(Request::class)));
    }
}

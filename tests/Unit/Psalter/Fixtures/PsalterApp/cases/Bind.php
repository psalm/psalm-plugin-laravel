<?php

declare(strict_types=1);

namespace PsalterFixture\Cases;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class BindService {}

final class Bind extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BindService::class, fn($app) => new BindService($app->make(Request::class)));
    }
}

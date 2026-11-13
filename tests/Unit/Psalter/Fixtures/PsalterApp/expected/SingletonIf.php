<?php

declare(strict_types=1);

namespace PsalterFixture\Cases;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class SingletonIfService {}

final class SingletonIf extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scopedIf(SingletonIfService::class, fn($app) => new SingletonIfService($app->make(Request::class)));
    }
}

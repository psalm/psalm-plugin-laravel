<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class MixedCaseService
{
}

final class MixedCase extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MixedCaseService::class, fn ($app) => new MixedCaseService($app->make(Request::class)));
    }
}

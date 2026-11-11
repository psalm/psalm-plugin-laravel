<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class TwoResolutionsService {}

final class TwoResolutions extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TwoResolutionsService::class, function ($app) {
            $request = $app->make(Request::class);
            $session = $app->make('session');

            return new TwoResolutionsService($request, $session);
        });
    }
}

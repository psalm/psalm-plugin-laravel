<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

final class ApplicationReceiverService {}

final class ApplicationReceiver
{
    public function register(Application $app): void
    {
        $app->singleton(ApplicationReceiverService::class, fn(Application $c) => new ApplicationReceiverService($c->make(Request::class)));
    }
}

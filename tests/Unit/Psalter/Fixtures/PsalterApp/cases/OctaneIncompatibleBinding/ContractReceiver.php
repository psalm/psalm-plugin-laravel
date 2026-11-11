<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

final class ContractReceiverService
{
}

final class ContractReceiver
{
    public function register(Container $app): void
    {
        $app->singleton(ContractReceiverService::class, fn (Container $c) => new ContractReceiverService($c->make(Request::class)));
    }
}

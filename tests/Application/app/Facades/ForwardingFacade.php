<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ForwardingService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: no own `@method` seal, root forwards unknown calls through a non-Macroable `__call`.
 */
class ForwardingFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ForwardingService::class;
    }
}

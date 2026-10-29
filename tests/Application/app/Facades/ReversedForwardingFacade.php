<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ReversedForwardingService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: root lists Macroable first but its forwarding trait wins `__call` via `insteadof`.
 */
class ReversedForwardingFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ReversedForwardingService::class;
    }
}

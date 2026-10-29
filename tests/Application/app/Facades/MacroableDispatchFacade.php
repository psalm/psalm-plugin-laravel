<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ForwardingService;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture: the root forwards, but `Macroable::__callStatic` replaces Laravel's
 * `Facade::__callStatic` and throws before the root's `__call` is reached.
 */
class MacroableDispatchFacade extends Facade
{
    use Macroable;

    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ForwardingService::class;
    }
}

<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\OwnCallMacroableService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: root has its own forwarding `__call` on top of Macroable.
 */
class OwnCallMacroableFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return OwnCallMacroableService::class;
    }
}

<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\MacroableOnlyService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: no own `@method` seal, root `__call` comes only from `Macroable`.
 */
class MacroableOnlyFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return MacroableOnlyService::class;
    }
}

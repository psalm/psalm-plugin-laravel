<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\PrecedenceMacroableService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: root `__call` is Macroable via `insteadof`, which throws for unknown methods.
 */
class PrecedenceMacroableFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return PrecedenceMacroableService::class;
    }
}

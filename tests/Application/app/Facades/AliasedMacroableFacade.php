<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\AliasedMacroableService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: root forwarding trait wins `__call`, Macroable only aliased.
 */
class AliasedMacroableFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return AliasedMacroableService::class;
    }
}

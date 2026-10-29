<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\StubbedMacroableService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: no own `@method` seal, root is Macroable-only at runtime but stubbed with a `__call` in Psalm.
 */
class StubbedMacroableFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return StubbedMacroableService::class;
    }
}

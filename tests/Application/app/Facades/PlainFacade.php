<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\PlainService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: no own `@method` seal, root has no `__call`.
 */
class PlainFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return PlainService::class;
    }
}

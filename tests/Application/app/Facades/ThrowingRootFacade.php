<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ThrowingConstructorService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: root has a non-Macroable `__call`, but its constructor throws, so the root never resolves.
 */
class ThrowingRootFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ThrowingConstructorService::class;
    }
}

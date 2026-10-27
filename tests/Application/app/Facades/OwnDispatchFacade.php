<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ForwardingService;
use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: the root forwards, but the facade's own `__callStatic` throws instead of
 * delegating to the root.
 */
class OwnDispatchFacade extends Facade
{
    /** @param list<mixed> $args */
    #[\Override]
    public static function __callStatic($method, $args): never
    {
        throw new \BadMethodCallException("Method {$method} does not exist.");
    }

    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return ForwardingService::class;
    }
}

<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: the accessor is a string alias that is not bound in the Testbench
 * container (the hypothetical binding would live in a user service provider that
 * our boot does not execute). {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler::tryGetFacadeRootClass()}
 * must return null without throwing, and method calls must fall through cleanly
 * to the standard UndefinedMagicMethod path.
 */
class UnboundAccessorFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'definitely-not-bound-in-testbench';
    }
}

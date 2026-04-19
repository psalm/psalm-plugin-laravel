<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Test fixture: the `@see` points at a class that does not exist. The resolver must
 * fall through gracefully (no fatal, no false-positive method resolution) so the
 * caller still sees the standard UndefinedMagicMethod.
 *
 * @see \App\Services\DefinitelyDoesNotExist
 */
class BrokenSeeFacade extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'definitely-not-bound';
    }
}

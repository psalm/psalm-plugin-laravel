<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\OwnCallMacroableFacade}: the class's own forwarding `__call`
 * overrides the one Macroable provides.
 */
class OwnCallMacroableService
{
    use Macroable;

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return null;
    }
}

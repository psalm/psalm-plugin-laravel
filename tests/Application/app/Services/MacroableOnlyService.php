<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\MacroableOnlyFacade}: the only `__call` comes from
 * `Macroable`, which throws `BadMethodCallException` for unknown methods instead of forwarding them.
 */
class MacroableOnlyService
{
    use Macroable;

    public function real(): int
    {
        return 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\StubbedMacroableFacade}. At runtime the only `__call` is
 * `Macroable`'s, which throws for unknown methods. The Psalm type tests additionally load
 * `tests/Type/stubbed-macroable-root.phpstub`, a class-level stub that redeclares this class with
 * its own `__call`, so Psalm attributes `__call` to this class instead of the trait.
 */
class StubbedMacroableService
{
    use Macroable;

    public function real(): int
    {
        return 1;
    }
}

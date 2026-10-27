<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\ForwardsUnknownCalls;
use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\PrecedenceMacroableFacade}: `insteadof` makes Macroable's
 * throwing `__call` win at runtime, although Psalm's storage names the forwarding trait.
 */
class PrecedenceMacroableService
{
    use ForwardsUnknownCalls, Macroable {
        Macroable::__call insteadof ForwardsUnknownCalls;
    }

    public function real(): int
    {
        return 1;
    }
}

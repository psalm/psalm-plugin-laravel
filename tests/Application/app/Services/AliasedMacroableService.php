<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\ForwardsUnknownCalls;
use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\AliasedMacroableFacade}: the forwarding trait wins `__call`
 * and Macroable's version stays reachable only under an alias.
 */
class AliasedMacroableService
{
    use ForwardsUnknownCalls, Macroable {
        ForwardsUnknownCalls::__call insteadof Macroable;
        Macroable::__call as macroCall;
    }
}

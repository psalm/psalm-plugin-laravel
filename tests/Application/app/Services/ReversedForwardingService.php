<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\ForwardsUnknownCalls;
use Illuminate\Support\Traits\Macroable;

/**
 * Test fixture for {@see \App\Facades\ReversedForwardingFacade}: Macroable is listed first, so Psalm's
 * storage names it, but `insteadof` makes the forwarding trait's `__call` win at runtime.
 */
class ReversedForwardingService
{
    use Macroable, ForwardsUnknownCalls {
        ForwardsUnknownCalls::__call insteadof Macroable;
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class SpreadArgs
{
    /** @param list<mixed> $args */
    public function render(array $args): View
    {
        // A spread shifts every position after it, so the walk refuses the call outright.
        return view('greeting', ...$args);
    }
}

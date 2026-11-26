<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class GateIncludeChain
{
    public function render(): View
    {
        return view('stale-gate-chain-host', []);
    }
}

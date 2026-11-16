<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class GateScopeRead
{
    public function render(): View
    {
        return view('stale-gate-scope-read', []);
    }
}

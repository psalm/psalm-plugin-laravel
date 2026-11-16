<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class GateFuncArgs
{
    public function render(): View
    {
        return view('stale-gate-func-args', []);
    }
}

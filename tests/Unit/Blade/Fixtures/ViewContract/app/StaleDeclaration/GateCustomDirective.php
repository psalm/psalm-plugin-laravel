<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class GateCustomDirective
{
    public function render(): View
    {
        return view('stale-gate-custom-directive', []);
    }
}

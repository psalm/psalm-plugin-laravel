<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class GatePropsLiteral
{
    /** `@props` compiles to `$$__key` writes, so even a readable list leaves the read set a lower bound. */
    public function render(): View
    {
        return view('stale-gate-props-literal', ['label' => 'x']);
    }
}

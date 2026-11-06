<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class StaleVar
{
    public function render(): View
    {
        return view('stale-var', ['campaign' => 'c']);
    }

    /** A second call site of the same template: the declaration is still reported once, at the template. */
    public function again(): View
    {
        return view('stale-var', ['campaign' => 'd']);
    }
}

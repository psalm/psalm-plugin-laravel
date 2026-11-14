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
}

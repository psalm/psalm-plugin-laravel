<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class CustomDirectiveInclude
{
    /** `runtimeOnly` is read by no template the literal name reaches: only a decline keeps it silent. */
    public function render(): View
    {
        return view('scoped-host', ['shown' => 1, 'nested' => 'n', 'runtimeOnly' => 'r']);
    }
}

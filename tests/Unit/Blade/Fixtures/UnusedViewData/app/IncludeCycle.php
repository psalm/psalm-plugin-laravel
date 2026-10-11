<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class IncludeCycle
{
    public function render(): View
    {
        return view('cycle-a', ['a' => 1, 'b' => 2, 'orphan' => 'x']);
    }
}

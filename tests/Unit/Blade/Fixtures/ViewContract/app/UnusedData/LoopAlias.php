<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class LoopAlias
{
    public function render(): View
    {
        return view('list', ['items' => [], 'item' => 'x']);
    }
}

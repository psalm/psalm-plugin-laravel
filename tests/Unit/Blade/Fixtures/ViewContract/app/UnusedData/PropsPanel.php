<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class PropsPanel
{
    public function render(): View
    {
        return view('props-panel', ['heading' => 'h', 'orphan' => 'x']);
    }
}

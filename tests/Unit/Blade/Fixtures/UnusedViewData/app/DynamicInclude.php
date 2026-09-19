<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class DynamicInclude
{
    public function render(): View
    {
        return view('dynamic-include', ['shown' => 1, 'orphan' => 'x']);
    }
}

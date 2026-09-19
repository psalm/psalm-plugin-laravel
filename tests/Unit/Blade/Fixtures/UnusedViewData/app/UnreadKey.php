<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class UnreadKey
{
    public function render(): View
    {
        return view('simple', ['name' => 'Ada', 'orphan' => 'x']);
    }
}

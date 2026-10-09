<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class IncludeChain
{
    public function render(): View
    {
        return view('host', ['title' => 't', 'deep' => 'd', 'orphan' => 'x']);
    }
}

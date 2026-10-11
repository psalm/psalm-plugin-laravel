<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class BrokenPhp
{
    public function render(): View
    {
        return view('broken-php', ['body' => 'b', 'orphan' => 'x']);
    }
}

<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class AmbientKeys
{
    public function render(): View
    {
        return view('ambient', ['body' => 'b', 'errors' => 'e', 'slot' => 's']);
    }
}

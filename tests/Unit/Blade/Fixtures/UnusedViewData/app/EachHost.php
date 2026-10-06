<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class EachHost
{
    public function render(): View
    {
        return view('each-host', ['rows' => [], 'cell' => 'c']);
    }
}

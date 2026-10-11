<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class Suppressed
{
    public function render(): View
    {
        /** @psalm-suppress UnusedViewData */
        return view('simple', ['name' => 'Ada', 'orphan' => 'x']);
    }
}

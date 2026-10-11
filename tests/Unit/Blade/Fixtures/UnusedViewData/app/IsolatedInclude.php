<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class IsolatedInclude
{
    public function render(): View
    {
        return view('isolated', ['own' => 1, 'secret' => 2]);
    }
}

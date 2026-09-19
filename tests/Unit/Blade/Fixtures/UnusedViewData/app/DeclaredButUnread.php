<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class DeclaredButUnread
{
    public function render(): View
    {
        return view('declared', ['label' => 'x']);
    }
}

<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class DeclaredInInclude
{
    public function render(): View
    {
        return view('declaring-host', ['heading' => 'h', 'note' => 'n']);
    }
}

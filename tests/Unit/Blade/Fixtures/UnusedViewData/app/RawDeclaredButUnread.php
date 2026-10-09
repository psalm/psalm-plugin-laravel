<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class RawDeclaredButUnread
{
    public function render(): View
    {
        return view('raw-declared', ['exception' => new \RuntimeException()]);
    }
}

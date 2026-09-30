<?php

declare(strict_types=1);

namespace UnusedViewDynamicFixture;

use Illuminate\Contracts\View\View;

final class RendersPage
{
    public function render(): View
    {
        return view('page');
    }
}

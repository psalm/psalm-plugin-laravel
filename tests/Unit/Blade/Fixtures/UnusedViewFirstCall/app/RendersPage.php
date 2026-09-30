<?php

declare(strict_types=1);

namespace UnusedViewFirstCallFixture;

use Illuminate\Contracts\View\View;

final class RendersPage
{
    public function render(): View
    {
        return view('page');
    }
}

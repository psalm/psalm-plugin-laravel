<?php

declare(strict_types=1);

namespace UnusedViewNumericNameFixture;

use Illuminate\Contracts\View\View;

final class RendersPage
{
    public function render(): View
    {
        return view('page');
    }
}

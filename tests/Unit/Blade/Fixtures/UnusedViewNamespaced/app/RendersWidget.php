<?php

declare(strict_types=1);

namespace UnusedViewNamespacedFixture;

use Illuminate\Contracts\View\View;

final class RendersWidget
{
    public function render(): View
    {
        return view('pkg::widget');
    }
}

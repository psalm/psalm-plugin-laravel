<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutMissing
{
    public function render(): View
    {
        return view('extends.profile', []);
    }
}

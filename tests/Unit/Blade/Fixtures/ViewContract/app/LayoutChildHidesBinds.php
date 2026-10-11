<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutChildHidesBinds
{
    public function render(): View
    {
        return view('extends.hides-binds', []);
    }
}

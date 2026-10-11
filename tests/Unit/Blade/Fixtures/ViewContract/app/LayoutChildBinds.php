<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutChildBinds
{
    public function render(): View
    {
        return view('extends.binds', []);
    }
}

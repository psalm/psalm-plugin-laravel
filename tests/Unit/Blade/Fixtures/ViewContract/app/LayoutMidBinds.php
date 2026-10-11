<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutMidBinds
{
    public function render(): View
    {
        return view('extends.onto-mid-binds', []);
    }
}

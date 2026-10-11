<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class LayoutCycle
{
    public function render(): View
    {
        return view('layouts.cycle-a', ['cycleName' => 'Acme']);
    }
}

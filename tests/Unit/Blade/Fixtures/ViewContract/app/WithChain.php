<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithChain
{
    public function render(): View
    {
        return view('greeting')->with('name', 123);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawMissing
{
    public function render(): View
    {
        return view('raw-contract', ['count' => 1]);
    }
}

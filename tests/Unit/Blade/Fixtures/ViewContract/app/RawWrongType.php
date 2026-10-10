<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawWrongType
{
    public function render(): View
    {
        return view('raw-contract', ['title' => 123, 'count' => null]);
    }
}

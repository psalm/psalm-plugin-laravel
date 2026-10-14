<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawOptional
{
    public function render(): View
    {
        return view('raw-contract', ['title' => 'Hello']);
    }
}

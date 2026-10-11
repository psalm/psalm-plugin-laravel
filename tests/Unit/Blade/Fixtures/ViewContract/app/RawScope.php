<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawScope
{
    public function render(): View
    {
        return view('raw-scope', ['thing' => new Thing()]);
    }
}

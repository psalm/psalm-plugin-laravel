<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawShortName
{
    public function render(): View
    {
        return view('raw-short', ['thing' => new Thing()]);
    }
}

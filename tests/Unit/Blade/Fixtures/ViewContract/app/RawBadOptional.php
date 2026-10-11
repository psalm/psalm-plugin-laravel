<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawBadOptional
{
    public function render(): View
    {
        return view('raw-bad-optional', ['bad' => 'x']);
    }
}

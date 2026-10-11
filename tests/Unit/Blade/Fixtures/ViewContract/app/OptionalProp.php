<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class OptionalProp
{
    public function render(): View
    {
        return view('panel', ['subtitle' => 'Today']);
    }
}

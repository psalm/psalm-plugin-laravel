<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class MissingVariable
{
    public function render(): View
    {
        return view('profile', []);
    }
}

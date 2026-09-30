<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WrongType
{
    public function render(): View
    {
        return view('greeting', ['name' => 123]);
    }
}

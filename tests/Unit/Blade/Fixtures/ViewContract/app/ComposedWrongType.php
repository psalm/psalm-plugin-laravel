<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComposedWrongType
{
    public function render(): View
    {
        return view('composed', ['title' => 123]);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class Correct
{
    public function render(): View
    {
        return view('profile', ['name' => 'Ada', 'age' => 36]);
    }
}

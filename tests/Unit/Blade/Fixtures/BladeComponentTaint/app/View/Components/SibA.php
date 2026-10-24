<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;

final class SibA extends SibBase
{
    #[\Override]
    public function render(): View
    {
        return view('components.sib-a');
    }
}

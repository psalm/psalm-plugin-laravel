<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;

final class SibB extends BaseCard
{
    #[\Override]
    public function render(): View
    {
        return view('components.sib-b');
    }
}

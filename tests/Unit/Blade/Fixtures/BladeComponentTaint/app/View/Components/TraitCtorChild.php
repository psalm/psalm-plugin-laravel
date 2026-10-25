<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;

final class TraitCtorChild extends TraitCtorBase
{
    #[\Override]
    public function render(): View
    {
        return view('components.trait-ctor-child');
    }
}

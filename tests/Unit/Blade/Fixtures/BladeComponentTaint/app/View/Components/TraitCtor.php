<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\PromotesMessage;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The constructor itself comes from a trait, so its parameter nodes are keyed by this class, not the trait. */
final class TraitCtor extends Component
{
    use PromotesMessage;

    #[\Override]
    public function render(): View
    {
        return view('components.trait-ctor');
    }
}

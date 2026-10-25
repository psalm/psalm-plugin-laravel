<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** `render()` is not one literal view, so no template is known to be this class's. */
final class Unmapped extends Component
{
    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view(\strtolower('Components.Unmapped'));
    }
}

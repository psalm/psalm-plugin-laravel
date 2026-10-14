<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Not final: `static` must still resolve to the class, not keep the late-static marker. */
class Pill extends Component
{
    use PillHelpers;

    public function fresh(): static
    {
        return $this;
    }

    public function render(): View
    {
        return view('components.pill');
    }
}

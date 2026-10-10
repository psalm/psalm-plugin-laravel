<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A `->with()` link adds keys after the recognised call: the whole view declines. */
final class Withed extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.withed')->with('title', 1);
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Its view has a composer that supplies `label`, so the property's type is not what the template gets. */
final class Composed extends Component
{
    public string $label = '';

    public int $count = 0;

    public function render(): View
    {
        return view('components.composed');
    }
}

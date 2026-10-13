<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Its view's `@props` default is not what the view sees: `data()` passes the property first. */
final class Sized extends Component
{
    public int $size = 3;

    public function render(): View
    {
        return view('components.sized');
    }
}

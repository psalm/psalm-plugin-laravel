<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** No `<x-untagged>` anywhere, so nothing is seeded, but the view is still this class's own. */
final class Untagged extends Component
{
    public int $size = 3;

    public function render(): View
    {
        return view('components.untagged');
    }
}

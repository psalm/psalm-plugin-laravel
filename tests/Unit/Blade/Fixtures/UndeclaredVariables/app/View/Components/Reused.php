<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Reused extends Component
{
    public function __construct(public string $label = 'x') {}

    public function render(): View
    {
        return view('components.reused');
    }
}

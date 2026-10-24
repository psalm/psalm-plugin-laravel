<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Two classes render this view. */
final class SharedYOne extends Component
{
    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components.shared-y');
    }
}

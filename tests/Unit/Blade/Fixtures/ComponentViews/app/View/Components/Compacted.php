<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A data array that is not a literal: its keys are unknown, so the whole view declines. */
final class Compacted extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.compacted', compact('title'));
    }
}

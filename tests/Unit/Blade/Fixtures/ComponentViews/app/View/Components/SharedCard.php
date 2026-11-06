<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Renders the Livewire component Shared's view. */
final class SharedCard extends Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.shared');
    }
}

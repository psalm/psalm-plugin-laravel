<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Its view is also the class component SharedCard's. */
final class Shared extends Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.shared');
    }
}

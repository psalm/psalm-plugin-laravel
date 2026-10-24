<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ComposedClosure extends Component
{
    public int $page = 1;

    public function render(): View
    {
        return view('livewire.composed-closure');
    }
}

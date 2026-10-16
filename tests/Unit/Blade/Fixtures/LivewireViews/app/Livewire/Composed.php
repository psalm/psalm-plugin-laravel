<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Composed extends Component
{
    public int $title = 1;

    public int $count = 0;

    public function render(): View
    {
        return view('livewire.composed');
    }
}

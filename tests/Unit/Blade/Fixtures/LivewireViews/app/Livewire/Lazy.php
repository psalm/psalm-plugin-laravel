<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Lazy extends Component
{
    public int $title = 0;

    public function placeholder(): View
    {
        return view('livewire.placeheld');
    }

    public function render(): View
    {
        return view('livewire.lazy');
    }
}

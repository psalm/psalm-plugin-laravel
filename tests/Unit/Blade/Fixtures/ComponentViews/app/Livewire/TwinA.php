<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/** TwinA and TwinB both render `livewire.twin`. */
class TwinA extends Component
{
    public string $tone = '';

    public function render(): View
    {
        return view('livewire.twin');
    }
}

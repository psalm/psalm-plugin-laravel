<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Rival extends Component
{
    public bool $compact = false;

    public function render(): View
    {
        if ($this->compact) {
            return view('livewire.rivaled');
        }

        return view('welcome');
    }
}

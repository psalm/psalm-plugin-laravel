<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class SharedCard extends Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.shared');
    }
}

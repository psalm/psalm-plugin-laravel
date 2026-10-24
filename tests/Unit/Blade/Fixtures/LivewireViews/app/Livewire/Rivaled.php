<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;

final class Rivaled extends \Livewire\Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.rivaled');
    }
}

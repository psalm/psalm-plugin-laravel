<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;

final class TwinTwo extends \Livewire\Component
{
    public string $tone = '';

    public function render(): View
    {
        return view('livewire.twin');
    }
}

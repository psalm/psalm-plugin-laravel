<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;

abstract class BaseListing extends \Livewire\Component
{
    public bool $base = true;

    public function render(): View
    {
        return view('livewire.listing');
    }
}

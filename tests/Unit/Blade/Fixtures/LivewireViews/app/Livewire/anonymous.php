<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Livewire\Component;

// The shape of a Livewire 4 multi-file component's class file.
return new class extends Component {
    public string $label = '';

    public function render(): View
    {
        return view('livewire.anon');
    }
};

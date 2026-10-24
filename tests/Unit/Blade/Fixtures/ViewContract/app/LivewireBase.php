<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Livewire\Component;

abstract class LivewireBase extends Component
{
    public function render(): View
    {
        // A subclass can add public properties this base class does not declare.
        return view('livewire.abstract');
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LivewireWrongType extends Component
{
    public int $count = 0;

    public function render(): View
    {
        return view('livewire.wrong');
    }
}

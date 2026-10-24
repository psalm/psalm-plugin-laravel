<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Livewire\Component;

final class LivewireWrongType extends Component
{
    public int $count = 0;

    public function render(): \Illuminate\Contracts\View\View
    {
        return view("greeting-count");
    }
}

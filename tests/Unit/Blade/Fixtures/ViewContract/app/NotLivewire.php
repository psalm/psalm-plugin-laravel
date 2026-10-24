<?php

declare(strict_types=1);

namespace ViewContractFixture;

final class NotLivewire
{
    public int $count = 0;

    public function render(): \Illuminate\Contracts\View\View
    {
        // The same render() and public property on a class that is not a Livewire component.
        return view("livewire-missing");
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Livewire\Component;

final class LivewireCounter extends Component
{
    public int $count = 0;

    public string $label = "";

    // Untyped: counts as supplied, never type-checked.
    public $loose;

    public function render(): \Illuminate\Contracts\View\View
    {
        // Livewire merges the component's public properties into the view, but not `$id`, which
        // Livewire's own base class declares.
        return view("livewire-counter");
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        // Only render() receives the properties: a placeholder view does not.
        return view("livewire-placeholder");
    }
}

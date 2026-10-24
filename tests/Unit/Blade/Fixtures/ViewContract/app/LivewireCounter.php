<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LivewireCounter extends Component
{
    public int $count = 0;

    public ?string $label = null;

    public $untyped;

    public static int $instances = 0;

    protected string $secret = 's';

    private string $hidden = 'h';

    public function render(): View
    {
        // Livewire renders this view with the component's public, non-static properties.
        return view('livewire.counter');
    }

    public function placeholder(): View
    {
        return view('livewire.placeholder');
    }
}

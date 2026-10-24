<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Livewire\Component;

final class LivewireVisibility extends Component
{
    public string $pub = '';

    protected string $prot = '';

    private string $priv = '';

    public static string $stat = '';

    public function render(): \Illuminate\Contracts\View\View
    {
        // Only the public non-static property reaches the view.
        return view('livewire-visibility');
    }
}

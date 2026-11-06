<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Also rendered by `@include` in page.blade.php, without the component's properties. */
final class Partial extends Component
{
    public string $title = '';

    public function render(): View
    {
        return view('livewire.partial');
    }
}

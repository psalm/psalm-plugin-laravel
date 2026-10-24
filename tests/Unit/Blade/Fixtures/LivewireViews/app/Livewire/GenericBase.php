<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * @template TItem of object
 */
abstract class GenericBase extends Component
{
    /** @var TItem|null */
    public ?object $item = null;

    public function render(): View
    {
        return view('livewire.generic');
    }
}

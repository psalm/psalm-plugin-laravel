<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

/** No render(): Livewire derives the view from the component name, which the seed does not guess. */
final class Conventional extends Component
{
    public string $name = '';
}

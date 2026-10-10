<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Overridden extends Component
{
    public string $label = '';

    /** @return array<string, mixed> */
    #[\Override]
    public function data(): array
    {
        return ['label' => 42];
    }

    public function render(): View
    {
        return view('components.overridden');
    }
}

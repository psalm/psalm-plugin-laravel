<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Spells its view with slashes: Laravel renders `components/slashed` as `components.slashed`. */
final class Slashed extends Component
{
    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components/slashed');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** A bare returned string is looked up as-is by Component::resolveView(): three `::` segments are inline Blade, not `pkg::components.suffixed`. */
final class Suffixed extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): string
    {
        return 'pkg::components/suffixed::x';
    }
}

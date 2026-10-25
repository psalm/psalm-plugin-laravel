<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A returned string is checked raw by `Component::resolveView()`: `pkg::components/inline::extra` is
 * an invalid view name (two `::`), so it renders as inline Blade and never names the pkg template.
 */
final class Inline extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View|string
    {
        return 'pkg::components/inline::extra';
    }
}

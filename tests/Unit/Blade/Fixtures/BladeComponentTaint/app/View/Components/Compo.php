<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Rendered through @component(Compo::class, ...), which compiles to `Compo::class::resolve(...)`. */
final class Compo extends Component
{
    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components.compo');
    }
}

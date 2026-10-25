<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** `data` is one of the names Component::data() never exposes. */
final class IgnoredName extends Component
{
    public function __construct(public string $data) {}

    #[\Override]
    public function render(): View
    {
        return view('components.ignored-name');
    }
}

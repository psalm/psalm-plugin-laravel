<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** `componentData()` replaces `$slot` with the rendered slot, whatever the property holds. */
final class Holder extends Component
{
    public function __construct(public string $slot) {}

    #[\Override]
    public function render(): View
    {
        return view('components.holder');
    }
}

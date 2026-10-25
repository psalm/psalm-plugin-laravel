<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** `view('components/slashed')` is the same view as `components.slashed` (ViewName::normalize()). */
final class Slashed extends Component
{
    public function __construct(public string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components/slashed');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Not public: `Component::data()` never hands the view this property. */
final class Hidden extends Component
{
    public function __construct(protected string $message) {}

    #[\Override]
    public function render(): View
    {
        return view('components.hidden');
    }
}

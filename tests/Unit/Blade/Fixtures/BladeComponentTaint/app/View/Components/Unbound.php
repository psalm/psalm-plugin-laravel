<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The tag key is not a constructor parameter, so the property never receives it. */
final class Unbound extends Component
{
    public string $message = '';

    public function __construct(public string $other = '') {}

    #[\Override]
    public function render(): View
    {
        return view('components.unbound');
    }
}

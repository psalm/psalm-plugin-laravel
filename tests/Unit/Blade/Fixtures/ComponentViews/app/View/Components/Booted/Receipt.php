<?php

declare(strict_types=1);

namespace App\View\Components\Booted;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The unqualified `view()` below resolves to a function the booted app declared, not Laravel's helper. */
final class Receipt extends Component
{
    public string $code = '';

    #[\Override]
    public function render(): View
    {
        return view('components.receipt');
    }
}

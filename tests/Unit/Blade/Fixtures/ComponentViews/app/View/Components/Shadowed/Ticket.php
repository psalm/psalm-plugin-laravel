<?php

declare(strict_types=1);

namespace App\View\Components\Shadowed;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The unqualified `view()` below resolves to this namespace's own function, not Laravel's helper. */
function view(string $name): View
{
    return \view($name, ['code' => 1]);
}

final class Ticket extends Component
{
    public string $code = '';

    #[\Override]
    public function render(): View
    {
        return view('components.ticket');
    }
}

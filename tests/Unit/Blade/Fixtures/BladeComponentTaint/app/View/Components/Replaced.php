<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The constructor replaces what it is given. */
final class Replaced extends Component
{
    public string $message;

    public function __construct(string $message)
    {
        $this->message = 'SAFE';
    }

    #[\Override]
    public function render(): View
    {
        return view('components.replaced');
    }
}

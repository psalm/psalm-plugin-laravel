<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The constructor escapes what it is given; the view only ever sees the escaped value. */
final class SanE extends Component
{
    public string $message;

    public function __construct(string $message)
    {
        $this->message = e($message);
    }

    #[\Override]
    public function render(): View
    {
        return view('components.san-e');
    }
}

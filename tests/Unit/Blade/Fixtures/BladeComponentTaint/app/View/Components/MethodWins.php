<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A public method of the same name wins in data(). */
final class MethodWins extends Component
{
    public function __construct(public string $message) {}

    public function message(): string
    {
        return 'SAFE';
    }

    #[\Override]
    public function render(): View
    {
        return view('components.method-wins');
    }
}

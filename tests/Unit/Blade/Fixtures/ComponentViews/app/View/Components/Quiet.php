<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A custom directive names its view but hands the call no scope, so it is no include. */
final class Quiet extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.quiet');
    }
}

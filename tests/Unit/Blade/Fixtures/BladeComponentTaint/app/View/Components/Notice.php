<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** A plain public property the constructor assigns. */
final class Notice extends Component
{
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }

    #[\Override]
    public function render(): View
    {
        return view('components.notice');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ConstructedPlain extends Component
{
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }

    #[\Override]
    public function render(): View
    {
        return view('components.constructed-plain');
    }
}

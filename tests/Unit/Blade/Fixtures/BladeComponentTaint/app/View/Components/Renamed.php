<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The tag key names the constructor parameter, not the property it is stored in. */
final class Renamed extends Component
{
    public string $text;

    public function __construct(string $label)
    {
        $this->text = $label;
    }

    #[\Override]
    public function render(): View
    {
        return view('components.renamed');
    }
}

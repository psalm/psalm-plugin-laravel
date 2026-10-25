<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Spells its view with slashes: Laravel renders `components/slash-render` as `components.slash-render`. */
final class SlashRender extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components/slash-render');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Its view has no `@props` and never names `$attributes`, but nests a tag of its own. */
final class Frame extends Component
{
    public string $heading = '';

    #[\Override]
    public function render(): View
    {
        return view('components.frame');
    }
}

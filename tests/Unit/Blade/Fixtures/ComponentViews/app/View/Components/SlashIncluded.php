<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Also rendered by a slash-spelled `@include` in page.blade.php, which runs no `data()`. */
final class SlashIncluded extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.slash-included');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Also rendered by `@include('components/linked')` in page.blade.php: the slash spelling names the same view. */
final class Linked extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.linked');
    }
}

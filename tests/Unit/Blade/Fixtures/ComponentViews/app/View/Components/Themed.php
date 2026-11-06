<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Also rendered by a custom include directive in page.blade.php (BookStack's theme `@include` override). */
final class Themed extends Component
{
    public string $title = '';

    #[\Override]
    public function render(): View
    {
        return view('components.themed');
    }
}

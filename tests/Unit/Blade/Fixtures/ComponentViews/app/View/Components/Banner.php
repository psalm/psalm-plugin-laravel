<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Banner and Promo both render `components.banner`, each with its own data. */
final class Banner extends Component
{
    public string $headline = '';

    public function render(): View
    {
        return view('components.banner');
    }
}

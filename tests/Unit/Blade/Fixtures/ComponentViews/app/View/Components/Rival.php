<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Renders the view the anonymous `<x-shared-card>` tag in page.blade.php renders without any `data()`. */
final class Rival extends Component
{
    public string $caption = '';

    public function render(): View
    {
        return view('components.shared-card');
    }
}

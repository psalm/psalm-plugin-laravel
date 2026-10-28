<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Gallery extends Component
{
    public string $caption = '';

    public function render(): View
    {
        return view('components.gallery');
    }
}

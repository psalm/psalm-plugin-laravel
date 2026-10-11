<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Card extends Component
{
    public string $title = '';

    public int $width = 1;

    public function render(): View
    {
        // `title` reaches the view from both render() and data(); which wins depends on the render path.
        return view()->make('components.card', ['title' => 'Card']);
    }
}

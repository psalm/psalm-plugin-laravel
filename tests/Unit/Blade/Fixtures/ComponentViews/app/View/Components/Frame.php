<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Frame extends Component
{
    public function __construct(public Renderable $content, public \Illuminate\View\View $inner) {}

    public function render(): View
    {
        return $this->view('components.frame');
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\View\Component;

final class Panel extends Component
{
    public function __construct(public string $heading = 'Panel', public string $subtitle = '') {}

    public function render(): View
    {
        return ViewFactory::make('components.panel');
    }
}

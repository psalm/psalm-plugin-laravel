<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Badge extends Component
{
    public int $count = 0;

    public function render(): View
    {
        return $this->view('components.badge');
    }
}

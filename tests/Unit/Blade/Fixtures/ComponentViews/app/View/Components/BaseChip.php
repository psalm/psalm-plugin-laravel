<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

abstract class BaseChip extends Component
{
    public function render(): View
    {
        return $this->view('components.chip');
    }
}

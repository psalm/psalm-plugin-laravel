<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Plain extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}

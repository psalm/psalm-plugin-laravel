<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

abstract class BaseNotice extends Component
{
    public function render(): string
    {
        return 'components.notice';
    }
}

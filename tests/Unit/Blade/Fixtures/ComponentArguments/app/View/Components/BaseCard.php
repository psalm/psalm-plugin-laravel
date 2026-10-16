<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

abstract class BaseCard extends Component
{
    public function __construct(public int $width) {}
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Gauge extends Component
{
    public function __construct(public float $value) {}

    public function render(): string
    {
        return '<meter></meter>';
    }
}

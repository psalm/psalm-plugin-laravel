<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Label extends Component
{
    public function __construct(public string $text) {}

    public function render(): string
    {
        return '<span></span>';
    }
}

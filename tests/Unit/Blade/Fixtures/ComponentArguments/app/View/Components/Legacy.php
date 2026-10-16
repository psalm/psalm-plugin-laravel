<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

final class Legacy extends Component
{
    /** @psalm-suppress MissingParamType an untyped parameter with no default is still required */
    public function __construct(public $value) {}

    public function render(): string
    {
        return '<div></div>';
    }
}

<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** The tag writes `max-value`; Laravel camelCases it onto `$maxValue`. */
final class Meter extends Component
{
    public function __construct(public int $maxValue) {}

    public function render(): string
    {
        return '<meter></meter>';
    }
}

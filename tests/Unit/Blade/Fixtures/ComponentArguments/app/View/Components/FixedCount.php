<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** `resolve()` filters the tag's data through this list, so `count` never reaches the constructor. */
final class FixedCount extends Component
{
    public function __construct(public int $count = 3) {}

    /** @return list<string> */
    #[\Override]
    protected static function extractConstructorParameters()
    {
        return [];
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

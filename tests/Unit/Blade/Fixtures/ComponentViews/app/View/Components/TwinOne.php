<?php

declare(strict_types=1);

namespace App\View\Components;

/** TwinOne and TwinTwo share BaseTwin::render(), with different data. */
final class TwinOne extends BaseTwin
{
    public string $tone = 'one';
}

<?php

declare(strict_types=1);

namespace App\View\Components;

/** `self` in a trait is the using class, never the trait. */
trait ToolbarHelpers
{
    public function me(): self
    {
        return $this;
    }
}

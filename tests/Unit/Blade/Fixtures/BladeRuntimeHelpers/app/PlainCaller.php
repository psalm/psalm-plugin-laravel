<?php

declare(strict_types=1);

namespace BladeRuntimeHelpersFixture;

/**
 * The blast-radius control: an ordinary project file calling the same helper. Psalm's own
 * visibility model leaves it undefined here, and the #1551 fix must not change that — only Blade
 * shadows get the booted app's function table.
 */
final class PlainCaller
{
    public function call(): string
    {
        return demo_helper();
    }
}

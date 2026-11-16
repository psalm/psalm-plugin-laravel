<?php

declare(strict_types=1);

namespace Fx;

/**
 * The blast-radius control: an ordinary project file calling a function the boot never declared
 * (it sits behind a version gate in the helper file). It must keep reporting `UndefinedFunction`:
 * the #1551 fix only merges the boot's declared names into Blade shadows, and a gated declaration
 * must not leak into project code. `demo_helper()` is no control: from Psalm 7.4 every function
 * the boot declared counts as predefined for every file.
 */
final class PlainCaller
{
    public function call(): string
    {
        return demo_never_declared();
    }
}

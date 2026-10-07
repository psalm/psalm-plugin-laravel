<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// The second `$component` type for templates that write or branch on it: `Widget` has no `gadget()`
// and this class has no `id()`, so each call reports exactly when the wrong type is in force.
final class Gadget
{
    public function gadget(): string
    {
        return 'g';
    }
}

<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

// A receiver for `$component` declarations: `Illuminate\View\AnonymousComponent` has no `id()`, so a
// call reports `PossiblyUndefinedMethod` exactly when a template's declared type was lost.
final class Widget
{
    public function id(): string
    {
        return 'w';
    }
}

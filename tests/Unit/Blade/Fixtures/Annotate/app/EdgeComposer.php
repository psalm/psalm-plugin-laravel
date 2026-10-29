<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\View\View;

/** Registered for `edge-composed` by the fixture's bootstrap. */
final class EdgeComposer
{
    public function compose(View $view): void
    {
        $view->with('title', 'Composed');
    }
}

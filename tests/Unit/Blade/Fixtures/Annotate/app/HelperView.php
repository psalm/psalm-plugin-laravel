<?php

declare(strict_types=1);

namespace AnnotateFixture;

use Illuminate\Contracts\View\View;

/** A private helper returns the bare view and its caller completes it, so its key set is open. */
final class HelperView
{
    public function show(): View
    {
        return $this->bare()->with('text', 'Ada');
    }

    private function bare(): View
    {
        return view('private-helper');
    }
}

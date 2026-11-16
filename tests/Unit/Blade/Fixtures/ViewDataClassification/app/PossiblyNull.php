<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

final class PossiblyNull
{
    public function render(?Member $member): View
    {
        return view('member', ['member' => $member]);
    }

    /** A Renderable renders to a string, so the null is all that is left to report. */
    public function nullableRenderable(?View $label): View
    {
        return view('label', ['label' => $label]);
    }
}

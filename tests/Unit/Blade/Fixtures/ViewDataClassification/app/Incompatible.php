<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

/** No part of the supplied type fits the declaration: a hard mismatch. */
final class Incompatible
{
    public function scalar(): View
    {
        return view('label', ['label' => 123]);
    }

    public function otherClass(Tag $tag): View
    {
        return view('member', ['member' => $tag]);
    }

    public function nullOnly(): View
    {
        return view('member', ['member' => null]);
    }

    public function unionWithNoFittingPart(Tag|int $value): View
    {
        return view('member', ['member' => $value]);
    }
}

<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

final class PossiblyFalse
{
    public function possiblyFalse(Member|false $member): View
    {
        return view('member', ['member' => $member]);
    }

    public function onlyFalse(): View
    {
        return view('member', ['member' => false]);
    }
}

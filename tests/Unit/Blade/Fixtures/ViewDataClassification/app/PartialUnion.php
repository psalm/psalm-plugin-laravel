<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;

final class PartialUnion
{
    public function render(Member|string $member): View
    {
        return view('member', ['member' => $member]);
    }
}

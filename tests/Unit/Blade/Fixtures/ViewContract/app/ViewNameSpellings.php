<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

/** Laravel normalizes `/` to `.` before resolving, so `inc/card` renders `inc.card`. */
final class ViewNameSpellings
{
    public function slashed(): View
    {
        return view('inc/card', ['title' => 1, 'count' => 2]);
    }
}

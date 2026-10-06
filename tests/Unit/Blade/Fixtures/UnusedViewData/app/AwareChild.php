<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class AwareChild
{
    public function render(): View
    {
        return view('aware-child', ['color' => 'red', 'orphan' => 'x']);
    }
}

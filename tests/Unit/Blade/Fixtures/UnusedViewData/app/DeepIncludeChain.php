<?php

declare(strict_types=1);

namespace UnusedViewDataFixture;

use Illuminate\Contracts\View\View;

final class DeepIncludeChain
{
    public function render(): View
    {
        return view('host', ['title' => 't', 'veryDeep' => 'v']);
    }
}

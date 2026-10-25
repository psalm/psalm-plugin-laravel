<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComposedUnusedKey
{
    public function render(): View
    {
        // The closure composer on `composed` is opaque: it may read `extra` from the view's data, so
        // the template not reading it proves nothing.
        return view('composed', ['title' => 'Hello', 'extra' => 1]);
    }
}

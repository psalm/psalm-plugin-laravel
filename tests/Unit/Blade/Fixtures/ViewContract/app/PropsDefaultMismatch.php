<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

/** `panel`'s `$title` defaults to a string; an int passed for it is not checked against that. */
final class PropsDefaultMismatch
{
    public function render(): View
    {
        return view('panel', ['title' => 123, 'subtitle' => 'Today']);
    }
}

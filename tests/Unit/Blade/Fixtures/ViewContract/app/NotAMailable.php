<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class NotAMailable
{
    public string $title = '';

    public function render(): View
    {
        // The same public property on a class the framework merges nothing into.
        return view('mail-plain');
    }
}

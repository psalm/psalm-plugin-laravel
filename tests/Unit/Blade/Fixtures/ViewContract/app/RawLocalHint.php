<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawLocalHint
{
    public function render(): View
    {
        // The template's raw `@var` types a local it assigns itself; it must never become a contract
        // the call site has to satisfy.
        return view('raw-local', []);
    }
}

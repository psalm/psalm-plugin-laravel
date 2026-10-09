<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class UncompilableFirstRoot
{
    public function render(): View
    {
        // 'uncompilable' resolves to the first view root's file, which fails to compile. The second
        // root's file declares $y and must not be consulted just because the first one was skipped.
        return view('uncompilable', []);
    }
}

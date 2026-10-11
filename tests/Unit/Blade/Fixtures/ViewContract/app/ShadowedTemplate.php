<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ShadowedTemplate
{
    public function render(): View
    {
        // 'dup' resolves to the first view root's file, which declares nothing. The second root's
        // file declares $x and must not be consulted.
        return view('dup', []);
    }
}

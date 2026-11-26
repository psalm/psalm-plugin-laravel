<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class StaleBehindLiteralComposer
{
    public function render(): View
    {
        return view('stale-composer-literal-host', []);
    }
}

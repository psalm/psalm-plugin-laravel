<?php

declare(strict_types=1);

namespace StaleDeclarationFixture;

use Illuminate\Contracts\View\View;

final class ReadInPartial
{
    /** `note` is read only by the included partial, so the declaration is live and the omission is reported. */
    public function render(): View
    {
        return view('stale-read-host', ['heading' => 'h']);
    }
}

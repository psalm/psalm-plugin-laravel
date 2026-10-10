<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RawLoopAlias
{
    public function render(): View
    {
        return view('raw-loop', ['members' => ['Ada']]);
    }
}

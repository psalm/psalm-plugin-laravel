<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithArrayAndSecondArgument
{
    public function render(): View
    {
        // An array key merges whatever the second argument says, so the earlier int is replaced and
        // the call is correct.
        return view('greeting', ['name' => 123])->with(['name' => 'Ada']);
    }
}

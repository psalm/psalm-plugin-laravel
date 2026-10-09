<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithArrayAndSecondArgument
{
    public function render(): View
    {
        // An array key takes the merge branch whatever the second argument is — Laravel dispatches
        // on is_array($key), never on the argument count — so the earlier int is replaced and the
        // call is correct. The second argument is a non-null literal on purpose: Rector's
        // RemoveNullArgOnNullDefaultParamRector deletes a literal null passed for a null-defaulted
        // parameter, which would silently delete the very shape this fixture exists to trigger.
        return view('greeting', ['name' => 123])->with(['name' => 'Ada'], 'ignored');
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

trait RenamedActionTrait
{
    private function renamedHelper(): View
    {
        return view('greeting');
    }
}

// The body stays reachable as a public method under the new name, so it is still checked.
final class AliasedToPublic
{
    use RenamedActionTrait {
        renamedHelper as public renamedAction;
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

trait PrivateActionTrait
{
    private function action(): View
    {
        return view('greeting');
    }
}

// The using class makes the trait's private method public: an action, so it is still checked.
final class AdaptedToPublic
{
    use PrivateActionTrait {
        action as public;
    }
}

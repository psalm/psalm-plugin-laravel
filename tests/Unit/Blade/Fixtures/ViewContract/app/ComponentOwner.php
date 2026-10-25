<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComponentOwner extends ComponentOwnerBase
{
    public function render(): View
    {
        // `parent` in the base's property type is Component, not the base.
        return view()->make('component-owner', ['component' => $this]);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComponentInherited extends VcBase
{
    public function render(): View
    {
        return view()->make('component-inherited', ['component' => $this]);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

final class StaticFacadeForm
{
    public function render(): View
    {
        return ViewFacade::make('greeting', ['name' => 123]);
    }
}

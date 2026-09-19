<?php

declare(strict_types=1);

namespace UnusedViewFacadeAliasFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

final class RendersPage
{
    public function render(): View
    {
        return ViewFacade::make('used');
    }
}

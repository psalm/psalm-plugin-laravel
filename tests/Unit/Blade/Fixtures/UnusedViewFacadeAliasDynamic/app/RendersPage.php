<?php

declare(strict_types=1);

namespace UnusedViewFacadeAliasDynamicFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

final class RendersPage
{
    public function render(string $name): View
    {
        return ViewFacade::make($name);
    }
}

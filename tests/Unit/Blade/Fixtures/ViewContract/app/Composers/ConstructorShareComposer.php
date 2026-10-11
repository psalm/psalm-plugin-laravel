<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View;

// The container builds the composer before it calls compose(), so this runs for every render.
final class ConstructorShareComposer
{
    public function __construct()
    {
        ViewFacade::share('count', 1);
    }

    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

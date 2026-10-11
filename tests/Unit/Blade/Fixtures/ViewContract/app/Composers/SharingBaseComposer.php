<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\Support\Facades\View as ViewFacade;

abstract class SharingBaseComposer
{
    public function __construct()
    {
        ViewFacade::share('count', 1);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

trait ComposesCount
{
    public function compose(View $view): void
    {
        $view->with('count', 1);
    }
}

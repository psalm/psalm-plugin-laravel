<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

trait ComposesTitle
{
    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

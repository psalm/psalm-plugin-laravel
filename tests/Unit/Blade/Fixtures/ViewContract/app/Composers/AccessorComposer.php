<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// Reading the view's name does not add or change data, so `$view` stays provable.
final class AccessorComposer
{
    public function compose(View $view): void
    {
        $view->with('currentAdminViewPath', $view->getName());
    }
}

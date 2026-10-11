<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// No constructor of its own: the inherited one shares `count`.
final class InheritedConstructorComposer extends SharingBaseComposer
{
    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

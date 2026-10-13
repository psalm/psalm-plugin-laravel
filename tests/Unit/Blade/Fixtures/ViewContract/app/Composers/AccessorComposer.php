<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// Reading the view's path leaves its data untouched, so `$view` stays provable.
final class AccessorComposer
{
    public function compose(View $view): void
    {
        $section = \basename(\dirname($view->getPath()));
        $view->with('section', $section);
    }
}

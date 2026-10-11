<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class WildcardComposer
{
    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

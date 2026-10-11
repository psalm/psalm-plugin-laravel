<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class DynamicKeyComposer
{
    public function compose(View $view): void
    {
        $key = \strtolower('TITLE');
        $view->with($key, 'x');
    }
}

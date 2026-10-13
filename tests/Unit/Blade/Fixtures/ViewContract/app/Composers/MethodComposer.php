<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class MethodComposer
{
    public function compose(View $view): void
    {
        $view->with('other', 1);
    }

    public function build(View $view): void
    {
        $view->with('count', 1);
    }

    public function dynamic(View $view): void
    {
        $view->with(\strtolower('TITLE'), 'x');
    }
}

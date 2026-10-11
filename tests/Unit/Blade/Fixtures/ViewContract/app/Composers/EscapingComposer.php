<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class EscapingComposer
{
    public function compose(View $view): void
    {
        $view->with('title', 'x');
        $this->decorate($view);
    }

    private function decorate(View $view): void
    {
        $view->with('count', 1);
    }
}

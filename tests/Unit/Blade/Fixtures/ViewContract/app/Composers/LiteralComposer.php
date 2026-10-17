<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class LiteralComposer
{
    public function compose(View $view): void
    {
        $view->with('title', 'x');
        $view->with(['tags' => []]);
    }
}

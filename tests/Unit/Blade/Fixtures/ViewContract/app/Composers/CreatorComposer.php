<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

final class CreatorComposer
{
    public function create(View $view): void
    {
        $view->with('title', 'x');
    }
}

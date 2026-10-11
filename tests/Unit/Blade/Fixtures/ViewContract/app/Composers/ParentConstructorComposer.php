<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// The parent constructor shares `count`; only this constructor's own body is visible to the reader.
final class ParentConstructorComposer extends SharingBaseComposer
{
    public function __construct()
    {
        parent::__construct();
    }

    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

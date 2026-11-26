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

    public function reads(View $view): void
    {
        $view->with('heading', \strtoupper((string) $view->getData()['label']));
    }

    public function dynamic(View $view): void
    {
        $view->with(\strtolower('TITLE'), 'x');
    }

    public function compacted(View $view): void
    {
        $vars = compact('view');
        $vars['view']->with('title', 'Ready');
    }
}

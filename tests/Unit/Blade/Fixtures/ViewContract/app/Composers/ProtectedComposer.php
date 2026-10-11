<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// Laravel calls compose() from outside the class, so PHP runs __call() for a protected method.
final class ProtectedComposer
{
    protected function compose(View $view): void
    {
        $view->with('title', 'x');
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): void
    {
        $arguments[0]->with('count', 1);
    }
}

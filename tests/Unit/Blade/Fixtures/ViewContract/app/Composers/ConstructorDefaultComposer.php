<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// A default value is evaluated when the container builds the composer, and `new` runs a constructor.
final class ConstructorDefaultComposer
{
    public function __construct(private readonly \stdClass $dependency = new \stdClass()) {}

    public function compose(View $view): void
    {
        $view->with('title', 'x');
    }
}

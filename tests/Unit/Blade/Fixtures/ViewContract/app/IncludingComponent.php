<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Renders its view through a local, a shape the class-component typing does not seed. */
final class IncludingComponent extends Component
{
    public function __construct(
        public readonly int $count,
    ) {}

    public function render(): View
    {
        $view = view('inc.component-view');

        return $view;
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentCtorExcept extends Component
{
    public int $count = 0;

    public function __construct()
    {
        // Not redeclared: only the constructor decides which names `data()` drops.
        $this->except = ['count', 'label'];
    }

    public function label(): string
    {
        return 'label';
    }

    public function render(): View
    {
        // Both names may be excluded at run time, so the passed values stay in place and the class's own
        // `int` and method wrapper must not replace them.
        return view()->make('component-ctor-except', ['count' => 'many', 'label' => 7]);
    }
}

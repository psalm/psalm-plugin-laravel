<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentClosureRenderTyped extends Component
{
    public int $count = 0;

    public function render(): Closure
    {
        // Built inside the closure `render()` returns: the properties arrive with their declared types.
        return static fn(array $data): View => view('component-closure-typed');
    }
}

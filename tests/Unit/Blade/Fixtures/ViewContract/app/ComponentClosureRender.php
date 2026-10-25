<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentClosureRender extends Component
{
    public int $count = 0;

    public function render(): Closure
    {
        // `renderComponent()` calls `$view->with($data)` on what the closure returns, so the view
        // built inside it receives the component's properties.
        return static fn(array $data): View => view('component-closure-render');
    }
}

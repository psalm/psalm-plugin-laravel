<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentHelperRender extends Component
{
    public int $count = 0;

    public function render(): View
    {
        return $this->buildView();
    }

    public function buildView(): View
    {
        // Built outside `render()` but returned by it, so the properties reach it: the component's
        // names count as supplied (untyped), and nothing is reported missing.
        return view('component-helper-render');
    }
}

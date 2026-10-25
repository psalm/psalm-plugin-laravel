<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentDataOverrideTypes extends Component
{
    /** @return array<string, mixed> */
    public function data(): array
    {
        return ['count' => 1];
    }

    public function render(): View
    {
        // The overridden data() can replace `count`, so the string passed here is not what the
        // template receives and is not judged against its `int` declaration.
        return view('component-override-types', ['count' => 'many']);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentDataOverride extends Component
{
    /** @return array<string, mixed> */
    public function data(): array
    {
        return ['title' => 'Ada', 'count' => 1];
    }

    public function render(): View
    {
        // A userland data() can add any key, so the supplied set is open and nothing is provably absent.
        return view('component-box', []);
    }
}

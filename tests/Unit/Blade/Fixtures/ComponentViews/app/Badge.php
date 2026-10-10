<?php

declare(strict_types=1);

namespace ComponentViewsFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Declines: a data() override can add keys no static walk enumerates. */
final class Badge extends Component
{
    public function render(): View
    {
        return view('components.badge', ['label' => 'New']);
    }

    #[\Override]
    public function data()
    {
        return ['label' => 42];
    }
}

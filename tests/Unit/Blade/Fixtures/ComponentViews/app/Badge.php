<?php

declare(strict_types=1);

namespace ComponentViewsFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\Component;

/** Declines: a data() override can add keys no static walk enumerates. */
final class Badge extends Component
{
    public function render(): View
    {
        return ViewFacade::make('components.badge', ['label' => 'New']);
    }

    #[\Override]
    public function data()
    {
        return ['label' => 42];
    }
}

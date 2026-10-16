<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\View\Component;

/** Shares its view with the other Wrapper: the view gets no typing seed, but is still a component view. */
final class WrapperB extends Component
{
    public function render(): string
    {
        return 'components.shared';
    }
}

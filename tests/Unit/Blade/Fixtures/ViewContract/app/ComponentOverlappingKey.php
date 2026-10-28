<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentOverlappingKey extends Component
{
    public function __construct(public readonly int $count) {}

    public function render(): View
    {
        // renderComponent() merges Component::data() into this view AFTER the array below, so the
        // template receives the int property, not the string placeholder.
        return view('component-count', ['count' => 'placeholder']);
    }
}

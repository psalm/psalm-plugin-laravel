<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentWrongType extends Component
{
    public function __construct(public readonly int $count) {}

    public function render(): View
    {
        return view('component-wrong', ['component' => $this]);
    }
}

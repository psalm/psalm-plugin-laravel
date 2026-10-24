<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentExceptedOpaque extends Component
{
    /** @var array<array-key, mixed> */
    protected $except = ['owner'];

    public parent $owner;

    public int $count = 0;

    public function render(): View
    {
        return view()->make('component-excepted-opaque', ['owner' => 'shown']);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class ComponentExcepted extends ComponentOwnerBase
{
    protected $except = ['owner'];

    public int $count = 0;

    public function render(): View
    {
        // `owner` is excluded from `data()`, so the passed value stays: the class's own `parent` type must not replace it.
        return view()->make('component-excepted', ['owner' => 'shown']);
    }
}

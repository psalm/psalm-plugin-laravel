<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

final class RenderableValue
{
    // view() returns Illuminate\View\View, whose __toString() already passes for a string.
    // The contract and a plain Renderable have none, which is the shape these two pin.
    public function contractTypedView(Factory $factory): View
    {
        return view('greeting', ['name' => $factory->make('plain')]);
    }

    public function userRenderable(): View
    {
        return view('greeting', ['name' => new RenderableWidget()]);
    }
}

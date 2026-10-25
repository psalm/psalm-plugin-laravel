<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class RenderableValue
{
    // A plain Renderable has no __toString(), which is the shape this pins: only gatherData()'s
    // render-to-string makes it a valid value for a `string` declaration.
    public function userRenderable(): View
    {
        return view('greeting', ['name' => new RenderableWidget()]);
    }
}

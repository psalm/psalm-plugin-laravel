<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentRenderPublic extends Component
{
    public function render(): View
    {
        // `render()` is public API: a caller may chain `->with([...])` on its result, so the data
        // set seen here is not closed and the declared `$name` is not reported missing.
        return view('greeting', []);
    }
}

<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class WithNullValue
{
    public function render(): View
    {
        // Laravel dispatches with() on is_array($key): this really does overwrite 'name' with null,
        // and the template declares it as string.
        return view('greeting', ['name' => 'Ada'])->with('name');
    }
}

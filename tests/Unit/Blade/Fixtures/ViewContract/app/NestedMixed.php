<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class NestedMixed
{
    /** @param list<mixed> $ids */
    public function render(array $ids): View
    {
        return view('nested-mixed', ['ids' => $ids]);
    }
}

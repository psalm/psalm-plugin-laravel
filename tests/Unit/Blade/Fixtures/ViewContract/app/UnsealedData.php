<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class UnsealedData
{
    /** @param array<string, mixed> $merge */
    public function render(array $merge): View
    {
        // $mergeData lands in the same data array, so 'age' cannot be proven absent — but 'name' is
        // still known, and still has to satisfy the declared type.
        return view('profile', ['name' => 123], $merge);
    }
}

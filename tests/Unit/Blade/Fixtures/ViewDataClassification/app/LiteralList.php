<?php

declare(strict_types=1);

namespace ViewClassifyFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

final class LiteralList
{
    /** @param Collection<int, array<string, mixed>> $rows */
    private function takesRows(Collection $rows): int
    {
        return $rows->count();
    }

    /** Psalm's own argument check rejects a collect() of a list: the key template infers as int<0, N>. */
    public function core(): int
    {
        return $this->takesRows(collect([['a' => 1], ['a' => 2]]));
    }

    public function view(): View
    {
        return view('rows', ['rows' => collect([['a' => 1], ['a' => 2]])]);
    }
}

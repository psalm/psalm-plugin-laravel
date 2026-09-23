<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\Contracts\Pagination\Paginator;

final class Source
{
    /**
     * @return array<int, string>
     */
    public function items(): array
    {
        return ['a', 'b'];
    }

    public function paginator(): Paginator
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public function count(): int
    {
        return 3;
    }
}

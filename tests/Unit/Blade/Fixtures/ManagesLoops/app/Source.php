<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\View\Factory;

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

    // Pins issue #1542's follow-up: the real getLastLoop() returns `(object) $last`, which IS a
    // \stdClass instance, not just something shaped like one. A userland caller relying on that
    // class identity (e.g. a ?\stdClass-typed wrapper) must stay clean.
    public function loopAsStdClass(Factory $factory): ?\stdClass
    {
        return $factory->getLastLoop();
    }
}

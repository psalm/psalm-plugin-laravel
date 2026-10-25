<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\View\Factory;

final class LoopWrapper
{
    // The real getLastLoop() returns `(object) $last`, which IS a \stdClass instance, not just something
    // shaped like one. A userland caller relying on that class identity (e.g. a ?\stdClass-typed
    // wrapper) must stay clean.
    public function loopAsStdClass(Factory $factory): ?\stdClass
    {
        return $factory->getLastLoop();
    }
}

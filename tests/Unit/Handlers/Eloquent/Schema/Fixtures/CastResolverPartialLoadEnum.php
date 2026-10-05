<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

// A top-level function is declared when the file is compiled, before the throw below runs. Including
// this file a second time therefore dies with an uncatchable "Cannot redeclare function" fatal.
function cast_resolver_partial_load_helper(): void {}

// Stands in for a load-time deprecation, which Psalm's error handler turns into an exception (#1652).
throw new \RuntimeException('raised while loading');

// Declared at run time, so the throw above leaves it undeclared.
if (true) {
    enum CastResolverPartialLoadEnum: string
    {
        case Open = 'open';
    }
}

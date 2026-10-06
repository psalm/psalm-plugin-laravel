<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures;

use function debug_backtrace as trace;

final class DebugBacktraceAliasProvider
{
    private string $mark = 'state';

    public function usesAliasedDebugBacktrace(): \Closure
    {
        return function (string $expression): string {
            return trace()[0]['object']->mark;
        };
    }
}

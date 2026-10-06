<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures;

use function compact as capture;

final class CompactAliasProvider
{
    private string $mark = 'state';

    public function usesAliasedCompact(): \Closure
    {
        return function (string $expression): string {
            return capture('this')['this']->mark;
        };
    }
}

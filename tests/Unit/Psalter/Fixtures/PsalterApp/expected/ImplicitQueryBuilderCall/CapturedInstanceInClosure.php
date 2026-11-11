<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/**
 * An instance call on a captured model reads no `$this`, so rebinding the closure cannot change it: it is
 * rewritten even inside a non-static closure or arrow function.
 */
final class CapturedInstanceInClosure
{
    public function run(Post $post): void
    {
        $rows = function () use ($post): void {
            $post->newQuery()->where('title', 'x')->get();
        };
        $rows();

        $arrow = fn() => $post->newQuery()->where('title', 'y')->get();
        $arrow();
    }
}

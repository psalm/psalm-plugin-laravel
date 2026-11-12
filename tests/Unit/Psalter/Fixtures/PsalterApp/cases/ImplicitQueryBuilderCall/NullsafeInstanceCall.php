<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/** `$post?->where()` is flagged like `$post->where()`; the insertion goes after the nullsafe arrow. */
final class NullsafeInstanceCall
{
    public function run(?Post $post): void
    {
        $post?->where('title', 'x')->get();
    }
}

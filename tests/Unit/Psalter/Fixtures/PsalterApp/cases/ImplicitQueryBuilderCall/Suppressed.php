<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class Suppressed
{
    /** @psalm-suppress ImplicitQueryBuilderCall */
    public function run(): void
    {
        Post::where('title', 'x')->get();
    }
}

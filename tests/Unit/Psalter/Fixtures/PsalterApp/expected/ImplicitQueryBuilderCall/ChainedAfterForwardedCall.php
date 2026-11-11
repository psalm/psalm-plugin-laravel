<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class ChainedAfterForwardedCall
{
    public function run(): void
    {
        Post::query()->where('title', 'x')->orderBy('id')->limit(5)->get();
    }
}

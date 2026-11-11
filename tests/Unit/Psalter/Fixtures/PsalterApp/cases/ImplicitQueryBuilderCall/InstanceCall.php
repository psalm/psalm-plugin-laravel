<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class InstanceCall
{
    public function run(Post $post): void
    {
        $post->where('title', 'x')->get();
    }
}

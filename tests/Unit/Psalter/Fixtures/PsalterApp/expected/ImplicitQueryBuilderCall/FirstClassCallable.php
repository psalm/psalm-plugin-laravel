<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class FirstClassCallable
{
    public function run(Post $post): void
    {
        $static = Post::where(...);
        $instance = $post->where(...);
    }
}

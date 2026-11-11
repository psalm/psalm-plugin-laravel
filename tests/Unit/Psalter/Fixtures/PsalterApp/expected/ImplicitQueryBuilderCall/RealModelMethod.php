<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class RealModelMethod
{
    public function run(Post $post): void
    {
        Post::all();
        Post::query()->where('title', 'x')->get();
        $post->newQuery()->where('title', 'x')->get();
        $post->save();
    }
}

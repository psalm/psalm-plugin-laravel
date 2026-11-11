<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

final class StaticBuilderMethod
{
    public function run(int $id): void
    {
        Post::query()->where('title', 'x')->get();
        Post::query()->find($id);
    }
}

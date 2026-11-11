<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class ArgsKept
{
    public function build(string $title): Post
    {
        return Post::make(
            ['title' => \strtoupper($title)],
        );
    }

    public function named(): Post
    {
        return Post::Make(attributes: ['title' => 'named']);
    }
}

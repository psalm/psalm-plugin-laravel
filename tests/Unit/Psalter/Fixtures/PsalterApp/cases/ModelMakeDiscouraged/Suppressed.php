<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class Suppressed
{
    /** @psalm-suppress ModelMakeDiscouraged */
    public function build(): Post
    {
        return Post::make();
    }
}

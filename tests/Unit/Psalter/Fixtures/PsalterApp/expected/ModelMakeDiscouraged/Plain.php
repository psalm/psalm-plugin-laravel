<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class Plain
{
    public function build(): Post
    {
        return new Post();
    }
}

<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class FirstClassCallable
{
    public function factory(): \Closure
    {
        return Post::make(...);
    }
}

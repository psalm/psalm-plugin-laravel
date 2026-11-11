<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class Chained
{
    public function method(): bool
    {
        return Post::make()->save();
    }

    public function nullsafe(): mixed
    {
        return Post::make(['title' => 'a'])?->title;
    }

    public function afterComment(): bool
    {
        return Post::make()
            // keep the comment where it is
            ->save();
    }

    public function arrayAccess(): mixed
    {
        return Post::make() /* c */ ['title'];
    }

    public function nested(): mixed
    {
        return Post::make(['title' => Post::make()->title]);
    }

    public function notChained(): bool
    {
        $post = Post::make();

        return $post->save();
    }
}

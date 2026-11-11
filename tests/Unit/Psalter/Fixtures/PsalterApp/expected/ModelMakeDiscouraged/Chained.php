<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

final class Chained
{
    public function method(): bool
    {
        return (new Post())->save();
    }

    public function nullsafe(): mixed
    {
        return (new Post(['title' => 'a']))?->title;
    }

    public function afterComment(): bool
    {
        return (new Post())
            // keep the comment where it is
            ->save();
    }

    public function arrayAccess(): mixed
    {
        return (new Post()) /* c */ ['title'];
    }

    public function nested(): mixed
    {
        return new Post(['title' => (new Post())->title]);
    }

    public function notChained(): bool
    {
        $post = new Post();

        return $post->save();
    }
}

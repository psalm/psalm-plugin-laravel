<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

/**
 * A named `Post::make()` inside a non-static method of a class that is a Post (here a subclass) runs
 * `$this->__call('make')` on the current instance, which keeps its class, table and connection, while
 * `new Post()` builds a fresh base model. Those calls are reported and never rewritten; a static method or a
 * static closure has no `$this`, so its call is rewritten.
 */
final class ObjectContextRelatedClass extends Post
{
    public function fromInstanceMethod(): Post
    {
        return Post::make(['title' => 'x']);
    }

    public function fromClosure(): Post
    {
        $run = function (): Post {
            return Post::make(['title' => 'y']);
        };

        return $run();
    }

    public static function fromStaticMethod(): Post
    {
        return (new Post(['title' => 'x']));
    }

    public function fromStaticClosure(): Post
    {
        $run = static function (): Post {
            return (new Post(['title' => 'y']));
        };

        return $run();
    }
}

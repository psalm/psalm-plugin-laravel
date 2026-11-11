<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\Post;

/**
 * A non-static closure or arrow function can be rebound with `Closure::call()`, `bind()` or `bindTo()` to any
 * model, which turns `Post::make()` into `$this->__call('make')` of that model (its table and connection). They
 * are reported and never rewritten, even in a static method of an unrelated class; a static closure cannot be
 * rebound, so its call is rewritten.
 */
final class NonStaticClosure
{
    public function rebound(Post $tenantPost): Post
    {
        $make = fn(): Post => Post::make(['title' => 'x']);

        return $make->call($tenantPost);
    }

    public static function fromStaticMethod(): Post
    {
        $make = function (): Post {
            return Post::make(['title' => 'y']);
        };

        return $make();
    }

    public static function staticClosure(): Post
    {
        $make = static fn(): Post => new Post(['title' => 'z']);

        return $make();
    }
}

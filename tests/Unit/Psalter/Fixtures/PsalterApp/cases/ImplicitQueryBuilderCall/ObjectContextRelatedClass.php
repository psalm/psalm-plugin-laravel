<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/**
 * A named `Post::where()` inside a non-static method of a class that is a Post (here a subclass) runs
 * `$this->__call()`: PHP keeps the object context, so the instance's table and connection apply, while
 * `Post::query()` builds a fresh model. Those calls are reported and never rewritten; a static method or
 * a static closure has no `$this`, so its call is rewritten.
 */
final class ObjectContextRelatedClass extends Post
{
    public function fromInstanceMethod(): void
    {
        Post::where('title', 'x')->get();
    }

    public function fromClosure(): void
    {
        $run = function (): void {
            Post::where('title', 'y')->get();
        };
        $run();
    }

    public function fromArrowFunction(): void
    {
        $run = fn() => Post::where('title', 'z')->get();
        $run();
    }

    public static function fromStaticMethod(): void
    {
        Post::where('title', 'x')->get();
    }

    public function fromStaticClosure(): void
    {
        $run = static function (): void {
            Post::where('title', 'y')->get();
        };
        $run();
    }
}

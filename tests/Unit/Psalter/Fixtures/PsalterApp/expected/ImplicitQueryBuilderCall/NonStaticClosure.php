<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/**
 * A non-static closure or arrow function can be rebound with `Closure::call()`, `bind()` or `bindTo()` to any
 * model, which turns `Post::where()` into `$this->__call('where')` of that model (its table and connection). They
 * are reported and never rewritten, even in a static method of an unrelated class; a static closure cannot be
 * rebound, so its call is rewritten.
 */
final class NonStaticClosure
{
    public function rebound(Post $tenantPost): mixed
    {
        $rows = fn() => Post::where('title', 'x')->get();

        return $rows->call($tenantPost);
    }

    public static function fromStaticMethod(): void
    {
        $rows = function (): void {
            Post::where('title', 'y')->get();
        };
        $rows();
    }

    public static function staticClosure(): void
    {
        $rows = static function (): void {
            Post::query()->where('title', 'z')->get();
        };
        $rows();
    }
}

<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\Post;

/**
 * The class scope is only rebindable inside a closure. A `self::$model` receiver in a method is rewritten, and so is
 * an instance call on a captured model in a `static` closure.
 */
final class ClassScopeOutsideClosure
{
    public static Post $model;

    public static function direct(): void
    {
        self::$model->newQuery()->where('title', 'x')->get();
    }

    public static function captured(Post $post): void
    {
        $rows = static fn() => $post->newQuery()->where('title', 'y')->get();
        $rows();
    }
}

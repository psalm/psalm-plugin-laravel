<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use Closure;
use PsalterFixture\Models\Post;

/**
 * A `static` closure has no `$this`, but `Closure::bind($closure, null, Other::class)` still rebinds its class scope:
 * a receiver containing `self`, `static` or `parent` then reads another class's property. Reported, never rewritten.
 */
final class StaticClosureClassScope
{
    public static Post $model;

    public static function callback(): \Closure
    {
        return static fn() => self::$model->where('title', 'x')->get();
    }

    public static function lateStatic(): \Closure
    {
        return static function (): void {
            static::$model->where('title', 'y')->get();
        };
    }
}

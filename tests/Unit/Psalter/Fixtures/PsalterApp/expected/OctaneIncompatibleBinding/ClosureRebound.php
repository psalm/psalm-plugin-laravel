<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class ClosureReboundService {}

/**
 * `Closure::bind($closure, null, Other::class)` changes what `self::$app` names, even for a `static` closure, and
 * `Closure::call()` changes `$this`: the rebound receiver can be a container whose `scoped()` takes other
 * parameters, so `scoped(abstract: ..., concrete: ...)` would throw. Reported, never renamed. Outside a closure the
 * same receivers are renamed (see Singleton).
 */
final class ClosureRebound extends ServiceProvider
{
    public static Application $container;

    public static function staticClosure(): \Closure
    {
        return static fn() => self::$container->singleton(abstract: ClosureReboundService::class, concrete: fn($c) => new ClosureReboundService($c->make(Request::class)));
    }

    public function register(): void
    {
        $this->app->booted(function (): void {
            $this->app->singleton(abstract: ClosureReboundService::class, concrete: fn($c) => new ClosureReboundService($c->make(Request::class)));
        });
    }
}

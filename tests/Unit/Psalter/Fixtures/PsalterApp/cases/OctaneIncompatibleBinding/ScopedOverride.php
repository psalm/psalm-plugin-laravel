<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

final class ScopedOverrideService {}

/**
 * Inherits `singleton()` but replaces `scoped()` with different parameter names: a renamed call such as
 * `singleton(abstract: ..., concrete: ...)` would throw, so the binding is reported and never rewritten.
 */
final class ScopedOverridingContainer extends Container
{
    /**
     * @param string|class-string $id
     * @param \Closure|string|null $factory
     */
    #[\Override]
    public function scoped($id, $factory = null): void
    {
        parent::scoped($id, $factory);
    }
}

final class ScopedOverride
{
    public function register(ScopedOverridingContainer $app): void
    {
        $app->singleton(ScopedOverrideService::class, fn($c) => new ScopedOverrideService($c->make(Request::class)));
    }
}

<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

final class ScopedIfOverrideService {}

/**
 * Inherits `singletonIf()` but replaces `scopedIf()` with different parameter names, so the binding is
 * reported and never rewritten.
 */
final class ScopedIfOverridingContainer extends Container
{
    /**
     * @param string|class-string $id
     * @param \Closure|string|null $factory
     */
    #[\Override]
    public function scopedIf($id, $factory = null): void
    {
        parent::scopedIf($id, $factory);
    }
}

final class ScopedIfOverride
{
    public function register(ScopedIfOverridingContainer $app): void
    {
        $app->singletonIf(ScopedIfOverrideService::class, fn($c) => new ScopedIfOverrideService($c->make(Request::class)));
    }
}

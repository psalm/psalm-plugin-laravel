<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

final class ScopedOnlyOverrideSingletonIfService {}

/**
 * Keeps Laravel's own `scopedIf()`, which calls `$this->scoped()`: that is this class's override with other
 * parameter names, so renaming `singletonIf()` to `scopedIf()` is unproven. The binding is reported and never
 * rewritten.
 */
final class ScopedOnlyOverridingContainer extends Container
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

final class ScopedOnlyOverrideSingletonIf
{
    public function register(ScopedOnlyOverridingContainer $app): void
    {
        $app->singletonIf(ScopedOnlyOverrideSingletonIfService::class, fn($c) => new ScopedOnlyOverrideSingletonIfService($c->make(Request::class)));
    }
}

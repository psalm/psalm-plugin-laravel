<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Http\Request;

final class BaseTypedService {}

/** A scanned subclass whose `scoped()` takes other parameters: `scoped(abstract: ..., concrete: ...)` would throw on it. */
final class BaseTypedOverridingContainer extends Container
{
    /**
     * @param string $id
     * @param \Closure|null $factory
     */
    #[\Override]
    public function scoped($id, $factory = null): void {}
}

/**
 * The declared type allows that subclass at runtime, so the call is reported and left as written for the
 * class and for its contract.
 */
final class BaseTypedContainer
{
    public function register(Container $app, ContainerContract $contract): void
    {
        $app->singleton(abstract: BaseTypedService::class, concrete: fn($c) => new BaseTypedService($c->make(Request::class)));
        $contract->singleton(abstract: BaseTypedService::class, concrete: fn($c) => new BaseTypedService($c->make(Request::class)));
    }
}

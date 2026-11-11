<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

final class UnionReceiverService {}

/** Keeps Laravel's own `scoped()`. */
final class UnionReceiverPlainContainer extends Container {}

/**
 * Replaces `scoped()` with other parameter names, like {@see ScopedOverridingContainer}.
 */
final class UnionReceiverOverridingContainer extends Container
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

/**
 * One edit renames the call for every member of the receiver's union, so a single member with its own
 * `scoped()` makes the rename unproven: the binding is reported and never rewritten.
 */
final class UnionReceiver
{
    public function register(UnionReceiverPlainContainer|UnionReceiverOverridingContainer $app): void
    {
        $app->singleton(UnionReceiverService::class, fn($c) => new UnionReceiverService($c->make(Request::class)));
    }
}

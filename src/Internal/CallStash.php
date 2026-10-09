<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Bridges a call node to the params-provider lookup that runs for it.
 *
 * Psalm's method-params provider event carries the call's args but not the call node, so a handler stashes the call
 * from `beforeExpressionAnalysis` keyed by its first Arg (the only call-identifying object the provider event
 * exposes) and reads it back in the provider. Both ends are weak: the call owns its Arg, so a strong value would
 * keep the key alive and the entry would outlive its AST.
 *
 * Only the mechanism is shared. The owning handler decides which calls to stash and when the stash is dropped
 * (hold it in a static and null it in the handler's `reset()`).
 *
 * @template TCall of object
 * @internal
 */
final class CallStash
{
    /** @psalm-var \WeakMap<\PhpParser\Node\Arg, \WeakReference<TCall>> */
    private \WeakMap $calls;

    /** @psalm-capabilities read-props */
    public function __construct()
    {
        /** @psalm-var \WeakMap<\PhpParser\Node\Arg, \WeakReference<TCall>> $calls */
        $calls = new \WeakMap();
        $this->calls = $calls;
    }

    /** @param TCall $call */
    public function put(\PhpParser\Node\Arg $firstArg, object $call): void
    {
        $this->calls->offsetSet($firstArg, \WeakReference::create($call));
    }

    /** @return TCall|null */
    public function get(\PhpParser\Node\Arg $firstArg): ?object
    {
        return ($this->calls[$firstArg] ?? null)?->get();
    }
}

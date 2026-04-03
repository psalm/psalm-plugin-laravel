<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

/**
 * Describes how a forwarded method call's return type should be transformed.
 *
 * Laravel's magic method forwarding (via __call/__callStatic) uses three distinct
 * return-type strategies. This enum maps directly to those runtime behaviors:
 *
 * - Decorated: Relation uses forwardDecoratedCallTo() — if the target (Builder)
 *   returns itself, the Relation returns $this instead, preserving the fluent chain.
 *
 * - AlwaysSelf: Eloquent\Builder's __call ignores the forwardCallTo result and
 *   unconditionally returns $this. The caller always gets the Builder back.
 *
 * - Passthrough: Model's __call returns whatever forwardCallTo returns. The raw
 *   result from the target (Builder) is passed through to the caller.
 */
enum ForwardingStyle: string
{
    /**
     * If the target method returns itself (detected by return type containing the
     * target class or 'static'), return the source's own generic type instead.
     * Otherwise, return the target's actual return type unchanged.
     *
     * Runtime equivalent: forwardDecoratedCallTo() in ForwardsCalls trait.
     * Used by: Relation → Builder
     *
     * Example: HasMany<Comment, Post>::where() → the Builder returns Builder,
     * so the Relation returns HasMany<Comment, Post> (not Builder).
     * But HasMany<Comment, Post>::first() → Builder returns TModel|null,
     * so that's returned as-is.
     */
    case Decorated = 'decorated';

    /**
     * Always return the source's own generic type, regardless of what the
     * target method returns. The target's return value is discarded.
     *
     * Runtime equivalent: forwardCallTo() + unconditional `return $this`.
     * Used by: Eloquent\Builder → Query\Builder
     *
     * Example: Builder<User>::whereIn() → always returns Builder<User>,
     * even though Query\Builder::whereIn() returns Query\Builder.
     */
    case AlwaysSelf = 'always_self';

    /**
     * Return the target method's return type as-is, with no transformation.
     * The source class is transparent — it just passes the result through.
     *
     * Runtime equivalent: forwardCallTo() with the result returned directly.
     * Used by: Model → Builder (static calls forwarded to newQuery())
     *
     * Example: User::where('active', true) → returns Builder<User>
     * (the result from Builder::where(), not User).
     */
    case Passthrough = 'passthrough';
}

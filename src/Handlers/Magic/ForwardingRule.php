<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

/**
 * Declarative description of one magic method forwarding hop.
 *
 * Each rule describes how one class forwards unresolved method calls to another:
 * - Which class is the source (where the __call fires)
 * - Which classes to search for the actual method declaration
 * - How to transform the return type
 *
 * Instead of writing a handler class per forwarding pattern, define a rule:
 *
 *     new ForwardingRule(
 *         sourceClass: Relation::class,
 *         searchClasses: [Builder::class, QueryBuilder::class],
 *         style: ForwardingStyle::Decorated,
 *         selfReturnIndicators: [Builder::class, 'static'],
 *         additionalSourceClasses: [HasMany::class, BelongsTo::class, ...],
 *     )
 *
 * The unified MethodForwardingHandler reads these rules and handles all of them.
 *
 * @psalm-immutable
 */
final class ForwardingRule
{
    /**
     * @param string $sourceClass The class whose method calls we intercept.
     *     The handler is registered for this class (and additionalSourceClasses).
     *
     * @param list<string> $searchClasses Classes to search for the method declaration,
     *     in priority order. First match wins. For Relation→Builder, this is
     *     [Builder::class, QueryBuilder::class] because Builder methods take priority.
     *
     * @param ForwardingStyle $style How to transform the return type.
     *
     * @param list<string> $selfReturnIndicators For Decorated style only: class names
     *     or keywords (like 'static') that indicate a "self-returning" method. When the
     *     target method's return type contains any of these, the source's own generic
     *     type is returned instead. Ignored for AlwaysSelf and Passthrough styles.
     *     Example: [Builder::class, 'static'] — if Builder::where() returns
     *     Builder<TModel> or static, the Relation returns itself.
     *
     * @param list<string> $additionalSourceClasses Extra classes to register the handler
     *     for. Psalm's provider lookup requires exact class name matching — a handler for
     *     Relation::class is not consulted for HasMany. List concrete subclasses here.
     *
     * @param bool $interceptMixin When true, the handler strips @mixin annotations from
     *     the source class's ClassLikeStorage during scanning. This forces methods that
     *     would normally resolve via @mixin to fall through to the __call path, where
     *     the handler intercepts them and provides existence, params, and return types.
     *
     *     Without this, methods like Relation::where() resolve via @mixin Builder,
     *     and the return type provider fires for Builder (not Relation) — our handler
     *     never sees the call. With interceptMixin=true, where() falls to __call,
     *     Psalm checks our MethodReturnTypeProvider for HasMany, and we return
     *     HasMany<Comment, Post> instead of Builder<TRelatedModel>.
     *
     *     When enabled, the handler takes full responsibility:
     *     - MethodExistence: confirms method exists on search classes
     *     - MethodVisibility: confirms it's public (forwarded via __call)
     *     - MethodParams: provides parameter types from the search class
     *     - MethodReturnType: applies ForwardingStyle (existing logic)
     *
     * @param string|null $description Human-readable description of this forwarding rule,
     *     useful for debugging and documentation.
     * @psalm-api
     */
    public function __construct(
        public readonly string $sourceClass,
        public readonly array $searchClasses,
        public readonly ForwardingStyle $style,
        public readonly array $selfReturnIndicators = [],
        public readonly array $additionalSourceClasses = [],
        public readonly bool $interceptMixin = false,
        /** @psalm-api used for debugging/introspection */
        public readonly ?string $description = null,
    ) {}

    /**
     * All classes that the handler should be registered for.
     *
     * @return list<string>
     */
    public function allSourceClasses(): array
    {
        return [$this->sourceClass, ...$this->additionalSourceClasses];
    }
}

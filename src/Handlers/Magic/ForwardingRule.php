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
     * @param bool $interceptMixin When true, the handler also registers for the mixin
     *     target classes (searchClasses) in getClassLikeNames(). When Psalm resolves a
     *     method via @mixin on a target class (e.g., Builder), the return type provider
     *     fires for that target. The handler inspects the calling expression's type to
     *     detect if it originated from a forwarding source (e.g., a Relation), then
     *     applies the ForwardingStyle to return the correct type.
     *
     *     The @mixin annotation stays intact — Psalm handles method existence and
     *     visibility via @mixin. The handler overrides only the return type (and
     *     provides method params for the source class's __call path).
     *
     *     Without this, methods like Relation::where() resolve via @mixin Builder,
     *     and the return type is Builder<TRelatedModel>. With interceptMixin=true,
     *     the handler intercepts that resolution and returns HasMany<Comment, Post>.
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

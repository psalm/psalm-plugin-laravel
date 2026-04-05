<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

/**
 * Declarative description of one magic method forwarding hop.
 *
 * @psalm-immutable
 */
final class ForwardingRule
{
    /**
     * @param class-string $sourceClass
     *     The base class whose method calls we intercept (e.g., Relation::class).
     *
     * @param non-empty-list<class-string> $searchClasses
     *     Classes to search for the actual method declaration, in priority order.
     *     First match wins. Example: [Builder::class, QueryBuilder::class].
     *
     * @param list<class-string> $selfReturnIndicators
     *     Class names that indicate a "self-returning" method. When the target method's
     *     return type contains any of these, the source's generic type is returned.
     *     Note: static/$this return types are detected automatically via value="static".
     *
     * @param list<class-string> $additionalSourceClasses
     *     Concrete subclasses to register the handler for. Psalm's provider lookup
     *     requires exact class name matching.
     *
     * @param bool $interceptMixin
     *     When true, also register for searchClasses (mixin targets) to intercept
     *     @mixin-resolved calls and restore the source's type.
     */
    public function __construct(
        public readonly string $sourceClass,
        public readonly array  $searchClasses,
        public readonly array  $selfReturnIndicators = [],
        public readonly array  $additionalSourceClasses = [],
        public readonly bool   $interceptMixin = false,
    ) {}


    /** @return non-empty-list<class-string> */
    public function allSourceClasses(): array
    {
        return [$this->sourceClass, ...$this->additionalSourceClasses];
    }
}

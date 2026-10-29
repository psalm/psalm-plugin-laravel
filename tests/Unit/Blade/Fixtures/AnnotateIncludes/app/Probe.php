<?php

declare(strict_types=1);

namespace AnnotateIncludesFixture;

/**
 * A generic whose methods and magic accessor change the receiver's own type, which Psalm only applies
 * to a receiver it can see the class of.
 *
 * @template T
 */
final class Box
{
    /** @param T $value */
    public function __construct(public mixed $value) {}

    public function label(): string
    {
        return 'box';
    }

    /** @psalm-this-out self<int> */
    public function reset(): void
    {
        $this->value = 42;
    }

    /** @psalm-this-out self<int> */
    public function __get(string $name): string
    {
        $this->value = 42;

        return '';
    }
}

/**
 * An empty generic: its `never` type argument is the one Psalm widens to whatever a by-value parameter
 * documents, once the argument is typed.
 *
 * @template T
 */
final class ProbeBox {}

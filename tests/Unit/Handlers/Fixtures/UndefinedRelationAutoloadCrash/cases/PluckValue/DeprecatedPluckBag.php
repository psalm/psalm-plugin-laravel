<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\PluckValue;

// Generic Arr::pluck() argument: ModelPropertyResolver::extractModelFromIterableValueType() checks for Enumerable.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/**
 * @template TKey of array-key
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 */
final class DeprecatedPluckBag implements \IteratorAggregate
{
    /** @return \ArrayIterator<TKey, TValue> */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator([]);
    }
}

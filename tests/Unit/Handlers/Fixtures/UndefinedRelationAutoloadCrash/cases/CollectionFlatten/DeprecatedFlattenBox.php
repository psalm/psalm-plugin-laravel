<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CollectionFlatten;

// Generic collection value of a flatten(1) receiver: CollectionFlattenHandler checks it for Enumerable.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/**
 * @template TKey of array-key
 * @template TValue
 */
final class DeprecatedFlattenBox {}

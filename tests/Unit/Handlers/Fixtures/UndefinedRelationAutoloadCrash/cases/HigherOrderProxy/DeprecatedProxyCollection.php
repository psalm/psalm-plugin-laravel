<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\HigherOrderProxy;

use Illuminate\Support\Collection;

// Custom collection behind a higher-order proxy: HigherOrderCollectionProxyHandler checks it for
// Enumerable and EloquentCollection.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

// Declared at run time (inside a block), so a load that threw leaves the class undeclared, like a
// compile-time deprecation does: each later autoloading check re-includes the file and fails again.
// A top-level declaration is hoisted before trigger_error() runs, and a second is_a() would find it.
if (true) {
    /**
     * @template TKey of array-key
     * @template TValue
     * @extends Collection<TKey, TValue>
     */
    final class DeprecatedProxyCollection extends Collection {}
}

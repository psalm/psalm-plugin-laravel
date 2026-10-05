<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Container;

// Named by a container binding that resolves to a class-name string: ContainerResolver checks whether
// the string is a class.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

// Declared at run time (inside a block), so a load that threw leaves the class undeclared, like a
// compile-time deprecation does: each later autoloading check re-includes the file and fails again.
if (true) {
    final class DeprecatedBoundService {}
}

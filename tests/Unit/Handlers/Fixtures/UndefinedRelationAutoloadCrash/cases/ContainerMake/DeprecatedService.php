<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ContainerMake;

// Resolved by name from the container: ContainerResolver checks the name with class_exists().
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

// Declared at run time (inside a block), so a load that threw leaves the class undeclared, like a
// compile-time deprecation does: each later autoloading check re-includes the file and fails again.
if (true) {
    final class DeprecatedService {}
}

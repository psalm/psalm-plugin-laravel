<?php

declare(strict_types=1);

// The house style of package helper files: guarded so a second include is a no-op. The guard is
// irrelevant to Psalm's visibility model, which is what #1551 is about — an unguarded declaration
// here behaves identically.

if (!\function_exists('demo_helper')) {
    function demo_helper(): string
    {
        return 'demo';
    }
}

if (!\defined('DEMO_CONST')) {
    \define('DEMO_CONST', 'demo-const');
}

// Never runs, but Psalm's scanner records both symbols in this file's storage all the same — the
// stand-in for a feature flag or a PHP-version gate. Merging a whole file's storage into shadows
// would make these resolvable and swallow the genuine diagnostics.
if (\PHP_MAJOR_VERSION < 5) {
    function demo_never_declared(): string
    {
        return 'never';
    }

    \define('DEMO_NEVER_DEFINED', 'never');
}

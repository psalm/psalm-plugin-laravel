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

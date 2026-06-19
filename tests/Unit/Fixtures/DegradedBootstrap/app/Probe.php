<?php

declare(strict_types=1);

namespace App;

/**
 * A trivial project file so Psalm has something to scan. Its findings are irrelevant —
 * the test asserts on the plugin's degraded-boot warning (stderr), not on analysis output.
 */
final class Probe
{
    public function run(): string
    {
        return 'ok';
    }
}

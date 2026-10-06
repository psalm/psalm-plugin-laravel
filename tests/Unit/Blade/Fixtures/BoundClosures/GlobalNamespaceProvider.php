<?php

declare(strict_types=1);

/**
 * Global namespace on purpose: only here does `namespace\compact` resolve to the built-in
 * `compact()`, which then hands out the bound object.
 */
final class GlobalNamespaceProvider
{
    public function usesRelativeCompact(): \Closure
    {
        return function (string $expression): string {
            return \get_debug_type(namespace\compact('this'));
        };
    }

    public function usesRelativeDebugBacktrace(): \Closure
    {
        return function (string $expression): string {
            return \get_debug_type(namespace\debug_backtrace()[0]['object'] ?? null);
        };
    }
}

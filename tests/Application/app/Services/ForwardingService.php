<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test fixture for {@see \App\Facades\ForwardingFacade}: a root with one real method and a
 * non-Macroable `__call` that forwards unknown calls, like a manager or proxy. Unlike
 * {@see DiagnosticService} it has no class-level `@method` tags.
 */
class ForwardingService
{
    public function known(): int
    {
        return 1;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return null;
    }
}

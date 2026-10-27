<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Test fixture: a trait whose `__call` forwards unknown calls (unlike Macroable's, which throws).
 */
trait ForwardsUnknownCalls
{
    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return null;
    }
}

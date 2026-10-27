<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test fixture for {@see \App\Facades\ThrowingRootFacade}: the container cannot build this root
 * because its constructor throws, so the facade root never resolves.
 */
class ThrowingConstructorService
{
    public function __construct()
    {
        throw new \RuntimeException('Root cannot be constructed.');
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return null;
    }
}

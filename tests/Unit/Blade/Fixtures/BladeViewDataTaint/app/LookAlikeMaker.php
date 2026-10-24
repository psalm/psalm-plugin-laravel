<?php

declare(strict_types=1);

namespace BladeViewDataTaintFixture;

/**
 * Not Laravel's view factory: a `make()` that happens to look like a compiled `@include`.
 *
 * @psalm-suppress UnusedClass, MissingImmutableAnnotation
 */
final class LookAlikeMaker
{
    /**
     * @psalm-pure
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function make(string $view, array $data = [], array $mergeData = []): string
    {
        return $view . \count($data) . \count($mergeData);
    }
}

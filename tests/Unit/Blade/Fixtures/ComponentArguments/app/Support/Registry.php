<?php

declare(strict_types=1);

namespace App\Support;

/** Not a component: a static `resolve()` of its own. */
final class Registry
{
    public function __construct(public int $count = 0) {}

    /** @param array<string, mixed> $data */
    public static function resolve(array $data): self
    {
        unset($data);

        return new self();
    }
}

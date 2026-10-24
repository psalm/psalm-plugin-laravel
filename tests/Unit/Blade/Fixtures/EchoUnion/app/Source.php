<?php

declare(strict_types=1);

namespace Fx;

/**
 * Producers with honest unions, so the echo-position gate is exercised without depending on any
 * one Laravel helper's own stub.
 */
final class Source
{
    /** @return array<array-key, mixed> */
    public function arr(): array
    {
        return [];
    }

    public static function opt(string $key): string|array|null
    {
        return $key;
    }

    public function maybeFalse(): string|false
    {
        return false;
    }

    public static function take(string $value): string
    {
        return $value;
    }
}

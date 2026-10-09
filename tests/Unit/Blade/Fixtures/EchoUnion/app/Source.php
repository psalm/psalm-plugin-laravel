<?php

declare(strict_types=1);

namespace Fx;

/**
 * Producers with honest unions, so the echo-position gate is exercised without depending on any
 * one Laravel helper's own stub. `old()` appears in the templates too, for the literal shape the
 * gate was reported against.
 */
final class Source
{
    /** @return array<array-key, mixed> */
    public function arr(): array
    {
        return [];
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

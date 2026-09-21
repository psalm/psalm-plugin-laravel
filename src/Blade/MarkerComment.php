<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/** @internal */
final class MarkerComment
{
    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     * @psalm-pure
     */
    public static function sourceLine(array|string $token, string $markerPrefix): ?int
    {
        if (!\is_array($token) || $token[0] !== \T_COMMENT) {
            return null;
        }

        $pattern = '/^\/\* ' . \preg_quote($markerPrefix, '/') . '(\d+) \*\/$/D';

        return \preg_match($pattern, $token[1], $match) === 1 ? (int) $match[1] : null;
    }
}

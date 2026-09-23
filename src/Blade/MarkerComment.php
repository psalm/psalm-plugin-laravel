<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/** @internal */
final class MarkerComment
{
    /**
     * The marker prefix for one template: salted with the source's own hash, then extended until it
     * is absent from that source. Nothing an author wrote can be read back as a marker — and, in
     * the other direction, nothing an author wrote can be cut out by {@see self::strip()}.
     *
     * @psalm-pure
     */
    public static function prefixFor(string $source): string
    {
        $prefix = 'blade:' . \hash('xxh128', $source) . ':';

        while (\str_contains($source, $prefix)) {
            $prefix .= ':';
        }

        return $prefix;
    }

    /**
     * $text with every marker comment removed, for comparing a piece of a shadow against the raw
     * template ({@see TemplateSnippetMatcher}). A marker sits INSIDE the text a multi-line construct
     * spans since #1544, so the comparison has to see past it.
     *
     * @psalm-pure
     */
    public static function strip(string $text, string $markerPrefix): string
    {
        $pattern = '/\/\* ' . \preg_quote($markerPrefix, '/') . '\d+ \*\/ ?/';

        return \preg_replace($pattern, '', $text) ?? $text;
    }

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

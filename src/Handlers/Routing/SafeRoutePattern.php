<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

/**
 * Decides whether a route-parameter regex constraint defeats every taint sink.
 *
 * "Safe" means the regex only matches characters from a conservative whitelist
 * — alphanumerics, underscore, dash, hyphen — so the value can never carry a
 * shell metachar, header CRLF, HTML brace, SQL quote, etc. A safe regex lets
 * the taint handler drop the `input` source from `Request::route('name')`.
 *
 * Permissive regexes (`.+`, `[^/]+`, anything outside the whitelist) are
 * rejected — taint stays.
 *
 * Two intentional limitations:
 *  - We accept the regex string verbatim from `Route::$wheres` /
 *    `Router::$patterns`. Laravel does not parse it; it just splices it into
 *    the compiled route pattern with `(?P<name>$regex)`. We mirror that — no
 *    PHP regex engine is invoked here.
 *  - We deliberately accept only well-known shapes. A novel shape that is in
 *    fact safe will be rejected; this keeps false-negatives at zero (a safe
 *    constraint we miss costs the user a noisy taint warning, not a missed
 *    vulnerability).
 *
 * @internal
 *
 * @psalm-immutable
 */
final class SafeRoutePattern
{
    /**
     * Patterns that match only characters in `[A-Za-z0-9_-]`. Anchors are
     * tolerated but not required (Laravel wraps the regex in `(?P<name>...)`).
     *
     * Each entry is the regex source (without delimiters) as it would appear
     * in `Route::$wheres`. Comparison is case-sensitive — Laravel doesn't
     * normalise these and neither does the runtime route compiler.
     *
     * @var list<string>
     */
    private const SAFE_PATTERNS = [
        // Digits
        '\d+',
        '[0-9]+',
        '\d*',
        '[0-9]*',

        // Alpha
        '[a-z]+',
        '[A-Z]+',
        '[a-zA-Z]+',
        '[A-Za-z]+',

        // Alphanumeric
        '[a-z0-9]+',
        '[A-Z0-9]+',
        '[a-zA-Z0-9]+',
        '[A-Za-z0-9]+',
        '[0-9a-zA-Z]+',
        '[0-9A-Za-z]+',

        // Slug-style (alphanumeric + dash/underscore)
        '[a-zA-Z0-9_-]+',
        '[A-Za-z0-9_-]+',
        '[a-zA-Z0-9-]+',
        '[A-Za-z0-9-]+',
        '[a-zA-Z0-9_]+',
        '[A-Za-z0-9_]+',
        '[\w-]+',
        '\w+',

        // Hex (UUID component shape, also covers whereIn-of-hex use cases)
        '[0-9a-f]+',
        '[0-9A-F]+',
        '[0-9a-fA-F]+',
        '[a-fA-F0-9]+',

        // Standard UUID. The first entry is exactly what Laravel's whereUuid()
        // shortcut emits in
        // Routing/CreatesRegularExpressionRouteConstraints::whereUuid() —
        // mixing the `\d` shorthand with `[a-fA-F]` literals. Comparison is
        // string-equal, so we keep both `\d`- and `[0-9]`-flavoured shapes.
        '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}',
        '[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}',
        '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',

        // ULID (Laravel's whereUlid shorthand). The first entry is exactly
        // what Routing/CreatesRegularExpressionRouteConstraints::whereUlid()
        // emits: a leading `[0-7]` (ULIDs are 128-bit and the first byte is
        // capped) plus 25 mixed-case Crockford base32 characters. The looser
        // shapes below tolerate hand-rolled where()s that approximate the
        // same shape.
        '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}',
        '[0-9A-HJKMNP-TV-Z]{26}',
        '[0-9A-Z]{26}',
    ];

    /** @psalm-pure */
    public static function isSafe(string $regex): bool
    {
        $trimmed = self::stripAnchors($regex);

        foreach (self::SAFE_PATTERNS as $candidate) {
            if ($trimmed === $candidate) {
                return true;
            }
        }

        // whereIn(name, [...]) compiles to an alternation of literal strings.
        // If every alternative is itself a safe literal (alphanumerics + dashes),
        // the overall pattern is safe. We don't strip optional whitespace —
        // Laravel's emitter doesn't insert any.
        if (\str_contains($trimmed, '|')) {
            $alternatives = \explode('|', $trimmed);
            foreach ($alternatives as $alt) {
                if ($alt === '' || \preg_match('/^[A-Za-z0-9_-]+$/', $alt) !== 1) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @psalm-pure */
    private static function stripAnchors(string $regex): string
    {
        // Laravel allows users to author wheres with or without ^ / $ anchors.
        // Both compile identically because the runtime route compiler wraps the
        // expression in a named group anyway. We strip them so the equality
        // check matches both styles.
        if (\str_starts_with($regex, '^')) {
            $regex = \substr($regex, 1);
        }

        if (\str_ends_with($regex, '$')) {
            $regex = \substr($regex, 0, -1);
        }

        return $regex;
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use function array_keys;
use function array_merge;
use function max;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
use function substr;
use function substr_count;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * Regex-based scanner that extracts variable references from a Blade template
 * and classifies each by echo context (escaped vs. raw vs. @php block).
 *
 * This is deliberately NOT a full Blade compiler. The scanner trades precision
 * for simplicity: it recognises the three echo syntaxes defined in
 * {@see BladeEchoKind} and extracts top-level variable names from each echo's
 * PHP expression. It does not:
 *
 *  - follow `@include` / `@extends` / `@yield` / `@section` data flows
 *  - resolve Blade components (`<x-foo :bar="$data" />`)
 *  - evaluate PHP expressions (e.g. `{!! $user->bio !!}` records `user`, not `bio`)
 *  - handle dynamic keys
 *
 * These are acceptable limitations for the initial taint-refinement use case:
 * we only need to know which *top-level* template variables reach a raw echo,
 * because the `view()` data array keys are always top-level variables after
 * `extract()`.
 *
 * Scope-introduced variables (Blade directive aliases such as `@foreach(... as
 * $item)`) are tracked separately so they can be excluded from the
 * "unsafe view-data keys" result. `$item` in `@foreach ($items as $item) {!!
 * $item !!} @endforeach` is NOT a view data key, so it must not surface.
 *
 * Edge-case handling:
 *  - `{{-- ... --}}` blade comments are stripped before scanning so tokens
 *    inside them are ignored.
 *  - `@verbatim ... @endverbatim` blocks are stripped (their contents are
 *    treated as literal text by Blade).
 *  - `@php ... @endphp` blocks are processed separately so their contents do
 *    not leak into the echo passes (strings like `"{!! x !!}"` inside @php
 *    would otherwise be matched as raw echoes).
 *  - `@{{ ... }}` / `@{!! ... !!}` (escaped braces) are treated as literal text.
 *  - `{{{ ... }}}` (legacy triple-brace escape) is treated as ESCAPED.
 *
 * @psalm-api
 */
final class BladeTemplateScanner
{
    /**
     * Scan a blade template source and return every variable reference found
     * inside an echo or @php block, paired with its echo kind and line number.
     *
     * @return list<BladeVariableUsage>
     *
     * @psalm-pure
     */
    public static function scan(string $source): array
    {
        $cleaned = self::stripCommentsAndVerbatim($source);
        $cleaned = self::protectEscapedBraces($cleaned);

        // Run the @php pass against the cleaned source, then blank those
        // regions before the echo passes. Without blanking, `{!! ... !!}`
        // inside a PHP string literal would match the raw-echo regex and
        // inject phantom "variables" from inside the literal.
        $phpUsages = self::collect(
            $cleaned,
            '/@php\b(?P<expr>.*?)@endphp\b/s',
            BladeEchoKind::PHP_BLOCK,
        );

        $withoutPhp = preg_replace_callback(
            '/@php\b.*?@endphp\b/s',
            /** @param array{0: string} $m */
            static fn(array $m): string => self::blank($m[0]),
            $cleaned,
        ) ?? $cleaned;

        // {!! ... !!} — raw echo. Extracted first so the shorter {{ ... }} pass
        // below runs on a template with raw-echo regions blanked out, avoiding
        // double-matches with {{{ ... }}} and mis-matches with stray `{{`.
        $rawUsages = self::collect(
            $withoutPhp,
            '/\{!!\s*(?P<expr>.*?)\s*!!\}/s',
            BladeEchoKind::RAW,
        );

        $legacyUsages = self::collect(
            $withoutPhp,
            '/\{\{\{\s*(?P<expr>.*?)\s*\}\}\}/s',
            BladeEchoKind::ESCAPED,
        );

        // Blank raw / legacy regions so the plain {{ ... }} pass cannot
        // re-match their contents.
        $remaining = preg_replace_callback(
            '/\{!!.*?!!\}|\{\{\{.*?\}\}\}/s',
            /** @param array{0: string} $m */
            static fn(array $m): string => self::blank($m[0]),
            $withoutPhp,
        ) ?? $withoutPhp;

        $escapedUsages = self::collect(
            $remaining,
            '/\{\{\s*(?P<expr>.*?)\s*\}\}/s',
            BladeEchoKind::ESCAPED,
        );

        return array_merge($rawUsages, $legacyUsages, $escapedUsages, $phpUsages);
    }

    /**
     * Extract the set of variable names that appear in at least one unescaped
     * echo context ({!! !!} or @php) AND are not introduced by a scope-local
     * directive (@foreach, @forelse). These are the keys a caller must treat
     * as html sinks when passing view data.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    public static function unsafeVariables(string $source): array
    {
        $scopeLocals = self::scopeLocalVariables($source);

        $unsafe = [];

        foreach (self::scan($source) as $usage) {
            if (!$usage->kind->isUnescaped()) {
                continue;
            }

            if (isset($scopeLocals[$usage->name])) {
                // e.g. `$item` in `@foreach ($items as $item)` — not a view
                // data key, so we must not surface it as unsafe.
                continue;
            }

            $unsafe[$usage->name] = true;
        }

        return array_keys($unsafe);
    }

    /**
     * Variables introduced by Blade control-flow directives that bind names in
     * local scope. These names cannot be view data keys (they are bound after
     * `extract()` runs), so they must be excluded from the unsafe-keys result.
     *
     * Covers:
     *  - `@foreach ($items as $x)` and `@foreach ($items as $k => $v)`
     *  - `@forelse (...)` (same alias syntax)
     *  - Simple inline assignments `@if ($x = expr)` / `@elseif (...)` / `@while (...)`,
     *    bounded to a single line.
     *
     * Known limitations:
     *  - Conditions that mix a function call with an assignment on the same line
     *    (for example `@if (count($rows) > 0 && $x = expr)`) fall through. The
     *    name is not recorded as scope-local, so if `$x` is later raw-echoed it
     *    will surface as an unsafe view-data key. This is a false positive at
     *    the sink layer; an explicit safe-list on the handler side can cover it.
     *  - The inline `@php($foo = expr)` directive (shorthand for `@php ... @endphp`)
     *    is not recognised. Same failure mode.
     *  - Nested `@foreach` inside a string literal (unlikely) is not stripped.
     *
     * @return array<string, true> name -> true, for O(1) membership
     *
     * @psalm-pure
     */
    private static function scopeLocalVariables(string $source): array
    {
        $locals = [];

        // @foreach / @forelse — `as $val` or `as $key => $val`.
        if (preg_match_all('/@for(?:each|else)\s*\(.*?\bas\s+\$([a-zA-Z_][a-zA-Z0-9_]*)(?:\s*=>\s*\$([a-zA-Z_][a-zA-Z0-9_]*))?/s', $source, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if ($name !== '') {
                    $locals[$name] = true;
                }
            }

            foreach ($matches[2] as $name) {
                if ($name !== '') {
                    $locals[$name] = true;
                }
            }
        }

        // Inline assignments in @if / @elseif / @while conditions. The
        // character class `[^()\n]*?` is intentionally line-scoped: proper
        // paren balancing requires a non-regex parser, so we accept the
        // single-line, no-nested-call shape and let the handler apply an
        // explicit safe-list for more complex cases.
        if (preg_match_all('/@(?:if|elseif|while)\s*\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=[^=]/', $source, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if ($name !== '') {
                    $locals[$name] = true;
                }
            }
        }

        return $locals;
    }

    /**
     * @return list<BladeVariableUsage>
     *
     * @psalm-pure
     */
    private static function collect(string $source, string $pattern, BladeEchoKind $kind): array
    {
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $usages = [];

        /** @var list<array{0: array{0: string, 1: int}, expr: array{0: string, 1: int}}> $matches */
        foreach ($matches as $match) {
            $offset = $match[0][1];
            // `max(1, ...)` pins the return type to `int<1, max>` for the
            // BladeVariableUsage constructor without a runtime assert.
            $line = max(1, 1 + substr_count(substr($source, 0, $offset), "\n"));

            foreach (self::extractVariables($match['expr'][0]) as $name) {
                $usages[] = new BladeVariableUsage($name, $line, $kind);
            }
        }

        return $usages;
    }

    /**
     * Replace a substring with whitespace while preserving `\n`. Keeping
     * newlines intact is critical: later passes compute line numbers from
     * `substr_count($prefix, "\n")` over the same buffer, so a blanked
     * multi-line `@php` / `@verbatim` / raw-echo region must not collapse
     * its interior newlines.
     *
     * Byte length is preserved because every non-newline character maps to a
     * single space.
     *
     * @psalm-pure
     */
    private static function blank(string $match): string
    {
        return preg_replace('/[^\n]/', ' ', $match) ?? $match;
    }

    /** @psalm-pure */
    private static function stripCommentsAndVerbatim(string $source): string
    {
        // Blade comments never reach the compiler; variables inside them are
        // never rendered, so we drop them.
        $source = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            /** @param array{0: string} $m */
            static fn(array $m): string => self::blank($m[0]),
            $source,
        ) ?? $source;

        // @verbatim blocks preserve their inner text literally — Blade does
        // not evaluate `{{ }}` inside them. Match either @endverbatim or EOF
        // so an unclosed @verbatim still strips to end-of-file.
        return preg_replace_callback(
            '/@verbatim\b.*?(?:@endverbatim\b|\z)/s',
            /** @param array{0: string} $m */
            static fn(array $m): string => self::blank($m[0]),
            $source,
        ) ?? $source;
    }

    /**
     * Replace `@{{` / `@{!!` (escaped opening braces) with a same-length
     * whitespace sequence so our echo regexes don't match them. Blade renders
     * these literally — they're commonly used for Vue/Alpine templates that
     * share the `{{ }}` syntax.
     *
     * @psalm-pure
     */
    private static function protectEscapedBraces(string $source): string
    {
        return str_replace(['@{{', '@{!!'], ['   ', '    '], $source);
    }

    /**
     * Extract top-level variable names from a PHP-like expression snippet.
     *
     * We purposefully only capture the identifier after the leading `$` and
     * before any property/method/index access. For `$user->bio`, we record
     * `user`; for `$data['html']`, we record `data`. This matches the
     * granularity of view data keys, which are top-level after `extract()`.
     *
     * Ignores: variable variables (`$$foo`), string interpolation inside
     * regex-like literals, and `static::`-prefixed identifiers (not `$`).
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private static function extractVariables(string $expr): array
    {
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $expr, $matches) === false) {
            return [];
        }

        /** @var list<non-empty-string> $names */
        $names = [];
        $seen = [];

        foreach ($matches[1] as $name) {
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $names[] = $name;
        }

        return $names;
    }
}

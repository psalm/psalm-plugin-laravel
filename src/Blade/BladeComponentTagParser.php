<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Standalone parser that extracts anonymous-component tag records from a
 * Blade template source.
 *
 * Runs after Laravel's `storePhpBlocks` / `storeVerbatimBlocks` so the input
 * never contains `@verbatim` or `@php ... @endphp` payloads — those have
 * already been replaced by single-line raw-block placeholders by the time
 * {@see PsalmBladeCompiler::compileComponentTags()} captures the value.
 * That means an `<x-foo>` token written inside `@verbatim` or `@php` cannot
 * reach the parser as a false positive.
 *
 * Surface intentionally narrow: only the subset of x-tag forms that v1 can
 * model soundly as data-flow edges produces a record. Everything else
 * raises {@see BladeComponentTagParseResult::$hasUnresolvable} so the
 * scanner can fall back to {@see BladeUncertaintyReason::ComponentTag}.
 *
 * Recognised as a resolvable edge:
 *
 *   - Self-closing tag with attribute string the v1 tokenizer accepts:
 *     `<x-foo />`, `<x-foo :bar="$expr" />`, `<x-foo bar="literal" />`,
 *     `<x-foo bar />` (boolean attr), `<x-foo :$bar />` (shorthand for
 *     `:bar="$bar"`). Bound expressions are extracted as their top-level
 *     `$varname` occurrences; scope-locals / framework-locals filtering is
 *     deferred to the scanner.
 *
 * Forces unresolvable (no edge, parent surfaces ComponentTag UNKNOWN):
 *
 *   - Opening tag with a body (`<x-foo ...>...</x-foo>`). Slot content
 *     would flow into the child's `$slot` scope-local, which the v1
 *     scanner does not propagate.
 *   - Namespaced tag (`<x-package::foo>` / `<x-mail::message>`). The map
 *     has no anonymous candidate names for namespaced anonymous-component
 *     namespaces; a future PR will add support.
 *   - `<x-dynamic-component>`. View name is runtime-dependent.
 *   - `@component(...)` or `@slot(...)` legacy directive forms. Defer to
 *     a follow-up PR once `<x-foo>` coverage stabilises.
 *   - Attribute string containing `@class(...)`, `@style(...)`, the
 *     `{{ $attributes ... }}` echo, or any token the v1 tokenizer cannot
 *     classify.
 *   - Bound attribute expression containing variable variables (`${...}`,
 *     `$$name`). The top-level variable name is opaque at parse time.
 *
 * Tag-boundary detection uses a small hand-rolled scanner instead of a
 * single regex because Blade attribute values routinely contain PHP
 * expressions (`<x-foo :bar="$arr ?? []" />`, `<x-foo @class([...]) />`,
 * `<x-foo :bar="$x > 0 ? 'a' : 'b'" />`) whose `>` characters break the
 * naïve `[^>]*?` regex shape. The scanner tracks string and parenthesis
 * state explicitly so a `>` inside `=>`, an attribute expression, or a
 * directive's argument list does not terminate the tag prematurely.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class BladeComponentTagParser
{
    /**
     * Seal the static-only contract. The class holds no instance state and
     * exposes only static methods; instantiation would be a programmer error.
     * {@see self::parse()} is the single public entry point.
     *
     * @psalm-api
     *
     * @psalm-mutation-free
     */
    private function __construct() {}

    /**
     * Per-attribute tokenizer pattern. Each iteration of the consuming loop
     * in {@see tokenizeAttributes()} advances the input position by the
     * length of one matched token.
     *
     * Branches (in order):
     *   1. `\G:\$(\w+)` — `:$name` shorthand (equivalent to `:name="$name"`).
     *   2. `\G:?[\w\-]+ = "..."` — quoted attribute (double).
     *   3. `\G:?[\w\-]+ = '...'` — quoted attribute (single).
     *   4. `\G:?[\w\-]+ = [^\s'"=<>]+` — unquoted attribute value.
     *   5. `\G:?[\w\-]+` — boolean attribute (no value).
     *
     * `\G` anchors to the previous match end, so a failed iteration is the
     * unresolvable signal (something the parser cannot model).
     */
    private const ATTR_PATTERN = '/\G\s*(?:'
        . '(?::\$(?<short>\w+))'
        . '|(?<colon1>:?)(?<name1>[\w\-]+)\s*=\s*"(?<dq>[^"]*)"'
        . "|(?<colon2>:?)(?<name2>[\w\-]+)\s*=\s*'(?<sq>[^']*)'"
        . '|(?<colon3>:?)(?<name3>[\w\-]+)\s*=\s*(?<uq>[^\s\'"=<>]+)'
        . '|(?<colon4>:?)(?<name4>[\w\-]+)'
        . ')/';

    /**
     * Parse a Blade source string (post-raw-block-storage) into a structured
     * result. The caller is responsible for resolving candidate view names
     * and filtering parent variables through scope-locals / framework-locals.
     *
     * @psalm-pure
     */
    public static function parse(string $source): BladeComponentTagParseResult
    {
        if (\preg_match('/@(?:component|slot)\b/', $source) === 1) {
            return new BladeComponentTagParseResult([], true);
        }

        $tags = self::scanTagBoundaries($source);

        $hasUnresolvable = false;
        $records = [];

        foreach ($tags as $tag) {
            if (!$tag['selfClosing']) {
                $hasUnresolvable = true;
                continue;
            }

            $name = $tag['name'];

            if (!self::isResolvableTagName($name)) {
                $hasUnresolvable = true;
                continue;
            }

            $attrs = self::tokenizeAttributes($tag['attrs']);

            if ($attrs === null) {
                $hasUnresolvable = true;
                continue;
            }

            $records[] = new BladeComponentTagRecord($name, $attrs);
        }

        return new BladeComponentTagParseResult($records, $hasUnresolvable);
    }

    /**
     * Walk the source character-by-character to find `<x-...>` / `<x-... />`
     * tag boundaries. Tracks single- / double-quote state and parenthesis
     * depth so `>` characters appearing inside attribute values, PHP
     * expressions, or `@class(...)`-style directives do not terminate the
     * tag.
     *
     * @return list<array{name: non-empty-string, attrs: string, selfClosing: bool}>
     *
     * @psalm-pure
     */
    private static function scanTagBoundaries(string $source): array
    {
        $len = \strlen($source);
        $pos = 0;
        $tags = [];

        while ($pos < $len) {
            // Find next `<x-` or `<x:`.
            $next = self::findTagStart($source, $pos);

            if ($next === null) {
                break;
            }

            $nameStart = $next['nameStart'];
            $nameEnd = $nameStart;

            while ($nameEnd < $len) {
                $c = $source[$nameEnd];

                // `ctype_alnum` covers `a-zA-Z0-9` in a single C call; the
                // `match` covers the four punctuation chars allowed in a
                // tag identifier. We deliberately avoid both
                // `in_array($c, ['_', '-', ':', '.'], true)` (rebuilds the
                // literal array on every iteration, linear scan) and a flat
                // `$c === '_' || ...` disjunction (Rector's
                // `RepeatedOrEqualToInArrayRector` rewrites it to the
                // `in_array` form). The `match` keeps the per-char cost
                // O(1) without tripping the rector rule.
                $isPunctuation = match ($c) {
                    '_', '-', ':', '.' => true,
                    default => false,
                };

                if (\ctype_alnum($c) || $isPunctuation) {
                    ++$nameEnd;
                    continue;
                }

                break;
            }

            if ($nameEnd === $nameStart) {
                $pos = $next['nameStart'];
                continue;
            }

            $name = \substr($source, $nameStart, $nameEnd - $nameStart);

            // Strip any trailing punctuation Laravel would not accept as
            // part of the tag identifier (defensive — the name-char loop
            // above already restricts to the dotted/colon subset).
            if ($name === '') {
                $pos = $nameEnd;
                continue;
            }

            $attrStart = $nameEnd;
            $cursor = $attrStart;
            $inQuote = null;
            $parenDepth = 0;
            $tagEndPos = null;
            $selfClosing = false;

            while ($cursor < $len) {
                $c = $source[$cursor];

                if ($inQuote !== null) {
                    if ($c === $inQuote) {
                        $inQuote = null;
                    }

                    ++$cursor;
                    continue;
                }

                if ($c === '"' || $c === "'") {
                    $inQuote = $c;
                    ++$cursor;
                    continue;
                }

                if ($c === '(') {
                    ++$parenDepth;
                    ++$cursor;
                    continue;
                }

                if ($c === ')') {
                    if ($parenDepth > 0) {
                        --$parenDepth;
                    }

                    ++$cursor;
                    continue;
                }

                if ($parenDepth > 0) {
                    ++$cursor;
                    continue;
                }

                if ($c === '/' && $cursor + 1 < $len && $source[$cursor + 1] === '>') {
                    $tagEndPos = $cursor + 2;
                    $selfClosing = true;
                    break;
                }

                if ($c === '>') {
                    $tagEndPos = $cursor + 1;
                    $selfClosing = false;
                    break;
                }

                if ($c === '<') {
                    // A `<` at tag depth 0 means the previous tag never
                    // closed — bail out of this tag and let the outer loop
                    // re-detect from the new `<`.
                    break;
                }

                ++$cursor;
            }

            if ($tagEndPos === null) {
                // Unclosed tag. Advance past the tag start so we don't
                // infinite-loop on the same `<x-`.
                $pos = $nameEnd;
                continue;
            }

            // For both branches, `$cursor` stops AT the close marker (the
            // `/` of `/>` for self-closing or the `>` for opening), so the
            // attribute substring excludes the close marker either way.
            $attrs = \substr($source, $attrStart, $cursor - $attrStart);

            $tags[] = [
                'name' => $name,
                'attrs' => $attrs,
                'selfClosing' => $selfClosing,
            ];

            $pos = $tagEndPos;
        }

        return $tags;
    }

    /**
     * Find the offset of the next `<x-name` or `<x:name` tag start at or
     * after `$pos`. Returns null if no further tag start exists.
     *
     * @return array{tagStart: int, nameStart: int}|null
     *
     * @psalm-pure
     */
    private static function findTagStart(string $source, int $pos): ?array
    {
        $len = \strlen($source);

        while ($pos < $len) {
            $ltPos = \strpos($source, '<', $pos);

            if ($ltPos === false) {
                return null;
            }

            if ($ltPos + 2 >= $len) {
                return null;
            }

            // After `<`, allow optional whitespace before `x` to mirror
            // Laravel's tolerance for `< x-foo>` (rare but supported).
            $cursor = $ltPos + 1;

            while ($cursor < $len && \ctype_space($source[$cursor])) {
                ++$cursor;
            }

            if ($cursor >= $len || $source[$cursor] !== 'x') {
                $pos = $ltPos + 1;
                continue;
            }

            ++$cursor;

            if ($cursor >= $len || ($source[$cursor] !== '-' && $source[$cursor] !== ':')) {
                $pos = $ltPos + 1;
                continue;
            }

            ++$cursor;

            return ['tagStart' => $ltPos, 'nameStart' => $cursor];
        }

        return null;
    }

    /**
     * True when the tag name corresponds to an anonymous-component v1 can
     * try to resolve: must be a non-namespaced identifier (no `::`),
     * must not be the `dynamic-component` built-in, and must not contain
     * the colon character used by namespaced tags (`<x:foo>` and similar
     * legacy spellings stay resolvable as long as they don't contain `::`).
     *
     * @psalm-pure
     */
    private static function isResolvableTagName(string $name): bool
    {
        if (\str_contains($name, '::')) {
            return false;
        }

        if ($name === 'dynamic-component') {
            return false;
        }

        // Tag must be a dotted-segment identifier ([a-zA-Z][\w-]*)(\.[a-zA-Z][\w-]*)*
        // Laravel's parser is more permissive, but the candidate-view-name
        // generator below only handles dotted segments cleanly.
        return \preg_match('/^[a-zA-Z][\w-]*(?:\.[a-zA-Z][\w-]*)*$/', $name) === 1;
    }

    /**
     * Walk the attribute string with {@see ATTR_PATTERN}. Returns null on the
     * first token the parser cannot classify (opaque @directive, unrecognised
     * character, variable variable in a bound expression).
     *
     * @return array{
     *   bound: array<non-empty-string, list<non-empty-string>>,
     *   static: list<non-empty-string>,
     * }|null
     *
     * @psalm-pure
     */
    private static function tokenizeAttributes(string $attrString): ?array
    {
        $trimmed = \trim($attrString);

        if ($trimmed === '') {
            return ['bound' => [], 'static' => []];
        }

        // Quick reject: any directive-shaped construct (`@class(...)`,
        // `@style(...)`, `{{...}}`) means the parser cannot enumerate
        // attributes statically.
        if (\preg_match('/@(?:class|style)\s*\(|\{\{|\{!!/', $trimmed) === 1) {
            return null;
        }

        $bound = [];
        $static = [];
        $pos = 0;
        $len = \strlen($attrString);

        while ($pos < $len) {
            // Skip whitespace explicitly; the pattern's \s* at start handles
            // it, but advancing $pos through whitespace also covers the
            // termination check below.
            while ($pos < $len && \ctype_space($attrString[$pos])) {
                ++$pos;
            }

            if ($pos >= $len) {
                break;
            }

            if (\preg_match(self::ATTR_PATTERN, $attrString, $m, 0, $pos) !== 1) {
                return null;
            }

            $consumed = \strlen($m[0]);

            if ($consumed === 0) {
                // Defensive: a zero-width match would infinite-loop.
                return null;
            }

            // Branch by which group captured the name. Each branch writes
            // all three locals before any read; the else returns early so
            // unset reads are unreachable.
            if (($m['short'] ?? '') !== '') {
                $rawName = $m['short'];
                $colonRaw = ':';
                $value = '$' . $rawName;
            } elseif (($m['name1'] ?? '') !== '') {
                $colonRaw = $m['colon1'] ?? '';
                $rawName = $m['name1'];
                $value = $m['dq'] ?? '';
            } elseif (($m['name2'] ?? '') !== '') {
                $colonRaw = $m['colon2'] ?? '';
                $rawName = $m['name2'];
                $value = $m['sq'] ?? '';
            } elseif (($m['name3'] ?? '') !== '') {
                $colonRaw = $m['colon3'] ?? '';
                $rawName = $m['name3'];
                $value = $m['uq'] ?? '';
            } elseif (($m['name4'] ?? '') !== '') {
                $colonRaw = $m['colon4'] ?? '';
                $rawName = $m['name4'];
                $value = null;
            } else {
                return null;
            }

            if ($rawName === '') {
                return null;
            }

            $camel = self::camelize($rawName);

            if ($camel === '') {
                return null;
            }

            if ($colonRaw === ':') {
                if ($value === null) {
                    // Bound attribute with no expression: only the
                    // `:$shorthand` form should have hit this branch, and
                    // that form already wrote `$shorthand` into $value.
                    // Anything else here is malformed.
                    return null;
                }

                if (\str_contains($value, '${') || \str_contains($value, '$$')) {
                    // Variable variables defeat top-level var extraction.
                    return null;
                }

                $bound[$camel] = self::extractTopLevelVars($value);
            } else {
                $static[] = $camel;
            }

            $pos += $consumed;
        }

        return ['bound' => $bound, 'static' => $static];
    }

    /**
     * Mirror Laravel's `Str::camel`: convert `kebab-case` and `snake_case` to
     * `camelCase`, leave `camelCase` and `PascalCase` unchanged. The variable
     * name the child component template sees for `<x-foo user-name="$x" />`
     * is `$userName`, not `$user-name` (which is not a valid identifier).
     *
     * @psalm-pure
     */
    private static function camelize(string $name): string
    {
        $studly = \str_replace([' ', '-', '_'], '', \ucwords($name, ' -_'));

        return \lcfirst($studly);
    }

    /**
     * Extract top-level `$varname` occurrences from a bound-attribute
     * expression. Excludes property/array indexing tails and method calls
     * because the LHS variable is the one whose taint flows.
     *
     * v1 limitation: occurrences inside single-quoted PHP string literals
     * within the expression are not stripped. Double-quoted interpolation
     * does flow the variable through to output, so matching both is
     * conservative. Pinned as a known limitation.
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private static function extractTopLevelVars(string $expression): array
    {
        if (\preg_match_all('/\$([a-zA-Z_]\w*)/', $expression, $matches) === false) {
            return [];
        }

        /** @var list<non-empty-string> $names */
        $names = [];
        $seen = [];

        /**
         * The capture group `([a-zA-Z_]\w*)` produces non-empty strings
         * by construction; the docblock narrows what Psalm's preg stub
         * widens to plain `string`. Rector dropped the runtime empty-check
         * as dead, so this annotation is the only narrowing left.
         *
         * @psalm-var non-empty-string $name
         */
        foreach ($matches[1] as $name) {
            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $names[] = $name;
        }

        return $names;
    }
}

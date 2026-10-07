<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Re-asserts an author-declared `$component` type after each top-level `<x-...>` tag's compiled
 * restore (#1701).
 *
 * A tag compiles to `$component = <Class>::resolve(...)` between a save and a restore of the
 * caller's `$component` (`CompilesComponents::compileComponent()` and `compileEndComponentClass()`).
 * The restore sits behind `isset($__componentOriginal<hash>)`, so Psalm unions the tag's own
 * component back into the declared type at its `endif` and a read after the tag reports
 * `PossiblyUndefinedMethod` against `AnonymousComponent`. The re-assert is the author's own
 * `@var` type string, verbatim, appended to the restore's final `endif;` on the SAME line (no line
 * and no new open tag, so {@see LineMapBuilder} and {@see SuppressionInjector} see nothing new).
 *
 * The type comes from the compiled string's own doc comments, not from {@see ContractRegistry}: only
 * a raw `<?php /** @var T $component *\/ ?>` or `@php` block types the template body, while a
 * `{{-- @var --}}` comment never reaches the compiled output. A carried type is where the
 * declaration stood when the tag SAVED it, which is what the restore puts back. Three shapes would
 * make that unsound, so the pass declines instead:
 *
 * - Nesting: after an inner tag's restore the runtime `$component` is the OUTER tag's component,
 *   so only a restore that empties the tag stack is re-asserted, and a declaration inside a tag
 *   body (it describes the child) never leaks out.
 * - A repeated hash: the hash is keyed by component NAME, so `<x-a><x-a /></x-a>` re-saves the same
 *   `$__componentOriginal<hash>` inside the outer tag, the inner restore unsets it, and the outer
 *   restore never fires (Laravel leaves the outer component in `$component` after the tag).
 * - A declaration that is not a plain class name: the save is `isset($component)`-gated, so a
 *   declared-nullable value that is null at runtime is not restored and `?Foo` would be wrong.
 *
 * Every shape this pass does not understand (a save without its restore, a restore for another
 * hash) returns the input unchanged. A match counts only at a real open tag per
 * {@see PhpTokenOffsets}, so author text that looks like a save or restore, inside a comment or
 * string, is ignored. A template that assigns `$component` itself is skipped whole, as
 * {@see AttributesRestoreReassert::templateAssignsAttributes()} does for `$attributes`.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class ComponentRestoreReassert
{
    private const NAME = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    /** `CompilesComponents.php:69`. Not always at line start: a tag inside another tag's body follows `?>` on its line. */
    private const SAVE_PATTERN = '/<\?php if \(isset\(\$component\)\) \{ \$__componentOriginal([0-9a-f]+) = \$component; \} \?>/';

    /** `CompilesComponents.php:103-106`. Template text can follow its `endif; ?>` on the same line. */
    private const RESTORE_PATTERN = '/<\?php if \(isset\(\$__componentOriginal([0-9a-f]+)\)\): \?>\R'
        . '<\?php \$component = \$__componentOriginal\1; \?>\R'
        . '<\?php unset\(\$__componentOriginal\1\); \?>\R'
        . '<\?php endif; \?>/';

    /** A plain (optionally namespaced) class name; a nullable, union, generic or shape type does not match. */
    private const DECLARATION_PATTERN = '/@(?:psalm-)?var\s+(\\\\?' . self::NAME . '(?:\\\\' . self::NAME . ')*)\s+\$component\b/';

    /**
     * Each way a template can write `$component`: a bare `=` (`==`/`===` and compound operators do
     * not match), `unset()`, `[..] =`/`list(..) =` destructuring, and a `foreach` value. Over-matching
     * (`$map[$component] = 1`) only withholds the re-assert.
     */
    private const ASSIGNMENT_PATTERNS = [
        '/\$component\s*=(?!=)/',
        '/\bunset\s*\([^)]*\$component\b/',
        '/(?:\[[^\[\]]*|\blist\s*\([^()]*)\$component\b[^\[\]()]*[\])]\s*=(?!=)/',
        '/\bas\s+(?:&?\s*\$\w+\s*=>\s*)?&?\s*\$component\b/i',
    ];

    /**
     * Whether the template's own source writes `$component`. Scanned over
     * {@see MarkerPrePass::blankInertText()}, so a mention in a Blade comment or `@verbatim` body
     * (never executed) cannot trip it. Accepted gaps, as for `$attributes`: `extract()`, `$$name`,
     * a by-reference out-parameter, and `@props`/`@aware` writing a `component` key.
     *
     * @psalm-pure
     */
    public static function templateAssignsComponent(string $source): bool
    {
        $executable = MarkerPrePass::blankInertText($source);

        foreach (self::ASSIGNMENT_PATTERNS as $pattern) {
            if (\preg_match($pattern, $executable) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-pure
     */
    public static function apply(string $compiled): string
    {
        // Most templates have no component tag, and every shadow goes through here.
        if (!\str_contains($compiled, '$__componentOriginal')) {
            return $compiled;
        }

        [$openTags, $docComments] = PhpTokenOffsets::scan($compiled);

        /** @var array<int, array{0: 'declare'|'save'|'restore', 1: ?string, 2: int}> $events offset => [kind, type or hash, restore length] */
        $events = [];

        foreach ($docComments as $offset => $docblock) {
            if (\preg_match_all('/\$component\b/', $docblock) > 0) {
                $events[$offset] = ['declare', self::declaredType($docblock), 0];
            }
        }

        foreach ([['save', self::SAVE_PATTERN], ['restore', self::RESTORE_PATTERN]] as [$kind, $pattern]) {
            \preg_match_all($pattern, $compiled, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (isset($openTags[$match[0][1]])) {
                    $events[$match[0][1]] = [$kind, $match[1][0], \strlen($match[0][0])];
                }
            }
        }

        \ksort($events);

        $declared = null;
        /** @psalm-var list<array{hash: string, type: ?string, collided: bool}> $stack */
        $stack = [];
        /** @psalm-var array<int, array{0: int, 1: string}> $edits offset => [restore length, type] */
        $edits = [];

        foreach ($events as $offset => [$kind, $value, $length]) {
            if ($kind === 'declare') {
                // Inside a tag body a declaration describes the child component, not the caller's.
                if ($stack === []) {
                    $declared = $value;
                }

                continue;
            }

            if ($kind === 'save') {
                foreach ($stack as $depth => $frame) {
                    if ($frame['hash'] === $value) {
                        $stack[$depth]['collided'] = true;
                    }
                }

                $stack[] = ['hash' => (string) $value, 'type' => $declared, 'collided' => false];

                continue;
            }

            $frame = \array_pop($stack);

            if ($frame === null || $frame['hash'] !== $value) {
                return $compiled;
            }

            if ($stack === [] && !$frame['collided'] && $frame['type'] !== null) {
                $edits[$offset] = [$length, $frame['type']];
            }
        }

        if ($stack !== []) {
            return $compiled;
        }

        \krsort($edits);

        foreach ($edits as $offset => [$length, $type]) {
            $restore = \substr($compiled, $offset, $length);
            $reasserted = \substr($restore, 0, -\strlen(' ?>')) . " /** @var {$type} \$component */ ?>";
            $compiled = \substr_replace($compiled, $reasserted, $offset, $length);
        }

        return $compiled;
    }

    /**
     * The docblock's own type string when it is the only `$component` mention and a plain class
     * name, otherwise null: a later declaration this pass cannot carry resets the type in force.
     *
     * @psalm-pure
     */
    private static function declaredType(string $docblock): ?string
    {
        if (
            \preg_match_all('/\$component\b/', $docblock) !== 1
            || \preg_match(self::DECLARATION_PATTERN, $docblock, $match) !== 1
            || \strcasecmp(\ltrim($match[1], '\\'), 'null') === 0
        ) {
            return null;
        }

        return $match[1];
    }
}

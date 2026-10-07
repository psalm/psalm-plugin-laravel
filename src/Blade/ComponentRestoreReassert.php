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
 * declaration stood when the tag SAVED it, which is what the restore puts back. Four shapes would
 * make that unsound, so the pass declines instead:
 *
 * - Nesting: after an inner tag's restore the runtime `$component` is the OUTER tag's component,
 *   so only a restore that empties the tag stack is re-asserted, and a declaration inside a tag
 *   body (it describes the child) never leaks out.
 * - A repeated hash: the hash is keyed by component CLASS (`AnonymousComponent:<alias>` for an
 *   anonymous one), so `<x-a><x-a /></x-a>` re-saves the same `$__componentOriginal<hash>` inside
 *   the outer tag, the inner restore unsets it, and the outer restore never fires (Laravel leaves
 *   the outer component in `$component` after the tag).
 * - A declaration that is not a plain class name: the save is `isset($component)`-gated, so a
 *   declared-nullable value that is null at runtime is not restored and `?Foo` would be wrong.
 *   Unions, generics and shapes are declined too: only a bare name is provably safe to repeat.
 * - A declaration that is not at block depth 0 (a branch arm, a loop body, a closure body): it is
 *   not the type in force at the tag, so it resets the carried type instead of replacing it.
 *
 * Every shape this pass does not understand (a save without its restore, a restore for another
 * hash) returns the input unchanged. A match counts only at a real open tag per
 * {@see PhpTokenOffsets}, so author text that looks like a save or restore, inside a comment or
 * string, is ignored. A template is skipped whole unless {@see self::templateOnlyReadsComponent()}
 * proves it never rewrites `$component` itself: Psalm sees such a write and types the variable
 * correctly, so the declared type must not replace it.
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

    /** Every `$component` occurrence, never as the tail of a longer name (`$components`). PHP variables are case-sensitive. */
    private const OCCURRENCE_PATTERN = '/\$component(?![A-Za-z0-9_\x80-\xff])/';

    /** What may follow a read: a member access, a comparison, `instanceof`, or `??` (but never `??=`). */
    private const READ_AFTER_PATTERN = '/^\s*(?:->|\?->|\?\?(?!=)|={2,3}|!==?|<>|instanceof\b)/i';

    /** The sole argument of the language construct `isset(`/`empty(` or `@isset(`/`@empty(`, never a method of that name. */
    private const INSPECTED_BEFORE_PATTERN = '/(?:@|(?<![\w>:$]))(?:isset|empty)\s*\(\s*$/i';

    /** The variable name of a `@var`/`@psalm-var <type> $component` declaration, type and name on ONE line. */
    private const DECLARATION_BEFORE_PATTERN = '/@(?:psalm-)?var[ \t]+\S+[ \t]+$/';

    /**
     * Whether EVERY `$component` in the template source is a proven read. An allowlist, because a
     * denylist of write shapes (`=`, `unset()`, destructuring, `foreach`, `catch`, references) is always one syntax short: declining only keeps the pre-tag finding. Bare uses
     * (`{{ $component }}`, a function argument, `$$component`), `??=` and a reversed comparison
     * (`$x === $component`) all decline. Scanned over the source with only `{{-- --}}` comments
     * removed: `@verbatim` is NOT blanked because raw PHP inside it still executes.
     *
     * Out of scope, since Psalm is equally blind to them without a tag, so the re-assert agrees
     * with its pre-tag view: `extract()`, `${'component'}`, `@aware`/`@props` writing a `component` key.
     *
     * @psalm-pure
     */
    public static function templateOnlyReadsComponent(string $source): bool
    {
        $source = \preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

        if ($source === null || \preg_match_all(self::OCCURRENCE_PATTERN, $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        foreach ($matches[0] as [$name, $offset]) {
            $before = \substr($source, \max(0, $offset - 200), \min(200, $offset));
            $after = \substr($source, $offset + \strlen($name), 200);

            $isRead = !\str_ends_with($before, '$') && (
                \preg_match(self::READ_AFTER_PATTERN, $after) === 1
                || (\preg_match(self::INSPECTED_BEFORE_PATTERN, $before) === 1 && \preg_match('/^\s*\)/', $after) === 1)
                || (\preg_match(self::DECLARATION_BEFORE_PATTERN, $before) === 1 && self::endsInsideDocComment($before))
            );

            if (!$isRead) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the text ends inside an unterminated `/** ... *\/`: a `// @var T` or `# @var T` line
     * comment is not a declaration, and it can sit right above a write.
     *
     * @psalm-pure
     */
    private static function endsInsideDocComment(string $before): bool
    {
        $open = \strrpos($before, '/**');

        return $open !== false && \strrpos($before, '*/', $open + 3) === false;
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

        /** @var array<int, array{0: 'declare'|'save'|'restore', 1: ?string, 2: int}> $events offset => [kind, type or hash, restore length or block depth] */
        $events = [];

        foreach ($docComments as $offset => [$docblock, $blockDepth]) {
            if (\preg_match_all('/\$component\b/', $docblock) > 0) {
                $events[$offset] = ['declare', self::declaredType($docblock), $blockDepth];
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
                // Inside a branch, loop or closure it is not the type in force at a later tag.
                if ($stack === []) {
                    $declared = $length === 0 ? $value : null;
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

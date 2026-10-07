<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Keeps the `@session`/`@context` value-stack save from reporting a redundant condition (#1724).
 *
 * `CompilesSessions::compileSession()` and `CompilesContexts::compileContext()` open a block with
 * `if (isset($value)) { $__sessionPrevious[] = $value; }`, an `isset()` on the author's own `$value`
 * that checks whether an outer value needs saving. Psalm checks it against whatever `$value` already
 * is (a foreach value, a literal, a `null` assignment, a docblock type) and reports
 * `RedundantCondition`/`TypeDoesNotContainType` on the directive line, a condition the author never
 * wrote and cannot change.
 *
 * Only that condition is rewritten, to `array_key_exists('value', get_defined_vars())`: the body is
 * untouched, so `$value` keeps the exact type it has today for every read after the block. It is safe
 * because {@see PreludeBuilder} declares every non-`__` name the compiled output mentions, so `$value`
 * is never undefined at the save. Psalm 6 and 7 do not narrow on `get_defined_vars()`.
 *
 * The pattern is anchored to the full compiler output, and a match counts only when its offset is a
 * real {@see \T_IF} per {@see \token_get_all()}: the same text inside a comment or string (an author's
 * own PHP comment, a `@verbatim` body) is never rewritten. The replacement stays on its line, so
 * {@see LineMapBuilder} sees nothing different.
 *
 * A template with an author-written `unset($value)` is left alone: past it the prelude declaration
 * no longer holds, and the rewritten condition proves nothing to Psalm, so the save's `$value` read
 * would report as undefined. The compiler's own end-of-block `unset($value)` restores the outer value
 * right after, so it does not count.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class ValueStackSaveRewrite
{
    /** `CompilesSessions.php` / `CompilesContexts.php`: group 1 is the directive, group 2 the condition to rewrite. */
    private const SAVE_PATTERN = '/if \((session|context)\(\)->has\(\$__\1Args\[0\]\)\) :\R'
        . '(if \(isset\(\$value\)\) \{) \$__\1Previous\[\] = \$value; \}\R'
        . '\$value = \1\(\)->get\(\$__\1Args\[0\]\); \?>/';

    /** The end-of-block restore that follows the compiler's own `unset($value)`. */
    private const RESTORE_PATTERN = '/unset\(\$value\);\Rif \(isset\(\$__(?:session|context)Previous\) && !empty\(\$__(?:session|context)Previous\)\)/';

    private const REWRITTEN_CONDITION = 'if (\array_key_exists(\'value\', \get_defined_vars())) {';

    /**
     * @psalm-pure
     */
    public static function apply(string $compiled): string
    {
        if (\preg_match_all(self::SAVE_PATTERN, $compiled, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) === false || $matches === []) {
            return $compiled;
        }

        [$ifOffsets, $authorUnsetsValue] = self::scan($compiled);

        if ($authorUnsetsValue) {
            return $compiled;
        }

        /** @var array<int, array{0: string, 1: int}> $match */
        foreach (\array_reverse($matches) as $match) {
            if (!isset($ifOffsets[$match[0][1]])) {
                continue;
            }

            [$condition, $offset] = $match[2];
            $compiled = \substr_replace($compiled, self::REWRITTEN_CONDITION, $offset, \strlen($condition));
        }

        return $compiled;
    }

    /**
     * Byte offsets of every genuine `if` token (never a range folded into a comment or string), and
     * whether any genuine `unset()` outside the compiler's restore shape names `$value`.
     *
     * @return array{array<int, true>, bool}
     *
     * @psalm-pure
     */
    private static function scan(string $compiled): array
    {
        $restoreOffsets = [];
        if (\preg_match_all(self::RESTORE_PATTERN, $compiled, $restores, \PREG_OFFSET_CAPTURE) !== false) {
            foreach ($restores[0] as [, $restoreOffset]) {
                $restoreOffsets[$restoreOffset] = true;
            }
        }

        $ifOffsets = [];
        $authorUnsetsValue = false;
        $inUnset = false;
        $offset = 0;

        foreach (\token_get_all($compiled) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_IF) {
                    $ifOffsets[$offset] = true;
                } elseif ($token[0] === \T_UNSET) {
                    $inUnset = !isset($restoreOffsets[$offset]);
                } elseif ($inUnset && $token[0] === \T_VARIABLE && $token[1] === '$value') {
                    $authorUnsetsValue = true;
                }
            } elseif ($token === ')') {
                $inUnset = false;
            }

            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        return [$ifOffsets, $authorUnsetsValue];
    }
}

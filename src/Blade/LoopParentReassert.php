<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Types `$loop->parent` as non-null inside a nested `@foreach`/`@forelse` (#1696).
 *
 * The ambient `$loop` and {@see \Illuminate\View\Concerns\ManagesLoops::getLastLoop()} are typed with
 * `parent: object|null`, the only sound answer for a single loop. Inside a loop nested in another one,
 * the compiled `$loop = $__env->getLastLoop();` is only reached with a live parent frame, so every
 * `$loop->parent->...` read would report `PossiblyNullPropertyFetch` on correct code.
 *
 * The nesting depth is counted on the COMPILED output, not the Blade source: Blade's own push
 * (`$__env->incrementLoopIndices(); $loop = $__env->getLastLoop();`, `@foreach`/`@forelse`) and pop
 * (`$__env->popLoop(); $loop = $__env->getLastLoop();`, `@endforeach`/bare `@empty`) assignments are
 * the single source of truth, so Blade comments, `@verbatim`, `@@foreach`, `@empty($x)` and
 * `@for`/`@while` (which never call `addLoop()`) need no handling. After each such assignment at
 * depth d >= 2 a `@var` docblock nests the shape d levels deep (`parent` is itself a full frame, see
 * `ManagesLoops::addLoop()`); a read after an inner `@endforeach` needs the pop-side re-assert too, as
 * Psalm resets `$loop` to the stub's depth-1 type there.
 *
 * Runtime depth is never below the lexical depth (`@break(N)`/`@continue(N)` only leak frames), so the
 * claim is sound for every compiled shape. It over-claims only for a hand-written `popLoop()`/`addLoop()`
 * call or a raw-PHP closure declared in a loop but run later. An `@include`d partial is its own shadow
 * starting at depth 0, and stays nullable. The docblock lands on the SAME line, right after the
 * assignment: no line and no `<?php` open tag is added, so {@see LineMapBuilder} and
 * {@see SuppressionInjector} see nothing different.
 *
 * A regex match alone is not proof the text is COMPILER output: the identical text can sit inside an
 * author's own PHP comment or string. `apply()` only accepts a match starting at a `T_VARIABLE` token
 * outside any quoted string per {@see \token_get_all()}. A depth that goes negative or does not end at
 * zero (an unclosed `@foreach`) returns the input unchanged.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class LoopParentReassert
{
    /** `CompilesLoops.php`: the push after `foreach (...):` and the pop after `endforeach;`. */
    private const PATTERN = '/\$__env->(?:incrementLoopIndices|popLoop)\(\); \$loop = \$__env->getLastLoop\(\);/';

    /** Bounds the emitted type's size for a pathologically deep template; a deeper read stays nullable. */
    private const MAX_NESTED_TYPE_DEPTH = 8;

    /** @psalm-pure */
    public static function apply(string $compiled): string
    {
        if (\preg_match_all(self::PATTERN, $compiled, $matches, \PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return $compiled;
        }

        $codeOffsets = self::variableOffsets($compiled);
        $depth = 0;
        /** @var array<int, int> $insertions byte offset right after a matched assignment => depth there */
        $insertions = [];

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[0] as $match) {
            [$whole, $offset] = $match;

            if (!isset($codeOffsets[$offset])) {
                continue;
            }

            $depth += \str_contains($whole, 'incrementLoopIndices') ? 1 : -1;

            if ($depth < 0) {
                return $compiled;
            }

            if ($depth >= 2) {
                $insertions[$offset + \strlen($whole)] = $depth;
            }
        }

        if ($depth !== 0) {
            return $compiled;
        }

        foreach (\array_reverse($insertions, true) as $at => $insertionDepth) {
            $compiled = \substr_replace($compiled, ' /** @var ' . self::loopTypeAtDepth($insertionDepth) . ' $loop */', $at, 0);
        }

        return $compiled;
    }

    /**
     * `\stdClass&object{<fields>, parent: <frame one level up>}`, repeated $depth times around the
     * depth-1 frame's own `parent: object|null`.
     *
     * @psalm-pure
     */
    private static function loopTypeAtDepth(int $depth): string
    {
        $type = 'object|null';

        for ($level = 1; $level <= \min($depth, self::MAX_NESTED_TYPE_DEPTH); $level++) {
            $type = '\stdClass&object{' . PreludeBuilder::LOOP_FIELDS . ', parent: ' . $type . '}';
        }

        return $type;
    }

    /**
     * Byte offsets of every `T_VARIABLE` token that is real code, never part of a comment (one token
     * to the lexer) or an interpolated string, heredoc, or backtick span, which PHP also tokenizes
     * into `T_VARIABLE`s.
     *
     * @return array<int, true>
     *
     * @psalm-pure
     */
    private static function variableOffsets(string $compiled): array
    {
        $offsets = [];
        $offset = 0;
        $inString = false;

        foreach (\token_get_all($compiled) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_START_HEREDOC) {
                    $inString = true;
                } elseif ($token[0] === \T_END_HEREDOC) {
                    $inString = false;
                } elseif ($token[0] === \T_VARIABLE && !$inString) {
                    $offsets[$offset] = true;
                }

                $offset += \strlen($token[1]);

                continue;
            }

            if ($token === '"' || $token === '`') {
                $inString = !$inString;
            }

            $offset += \strlen($token);
        }

        return $offsets;
    }
}

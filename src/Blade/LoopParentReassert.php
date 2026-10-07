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
 * claim is sound for every compiled shape. It over-claims only for a hand-written `popLoop()` call that
 * drops a frame the compiler's bookkeeping still counts, or a raw-PHP closure declared in a loop but run
 * later (an extra hand-written `addLoop()` only adds frames, so it never over-claims). An `@include`d partial is its own shadow
 * starting at depth 0, and stays nullable. The docblock lands on the SAME line, right after the
 * assignment: no line and no `<?php` open tag is added, so {@see LineMapBuilder} and
 * {@see SuppressionInjector} see nothing different.
 *
 * Provenance, not text, decides what counts: author PHP (a branch, a string, a comment) can spell the
 * same statements without ever running them. A push or pop is counted only when it opens the
 * compiled directive's own PHP block, i.e. the `<?php` open tag is followed (whitespace and comments
 * aside) by the compiler's exact prologue, verified token by token (the loop expression and alias are
 * author code, so they are skipped by bracket depth, not matched). Text inside any string, heredoc or
 * comment can never start at an open tag. An `@php` block that reproduces the whole prologue at its
 * start does run a real `addLoop()`/`popLoop()`, so counting it is sound. A depth that goes negative
 * or does not end at zero (an unclosed `@foreach`) returns the input unchanged.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class LoopParentReassert
{
    /** Bounds the emitted type's size for a pathologically deep template; a deeper read stays nullable. */
    private const MAX_NESTED_TYPE_DEPTH = 8;

    /** `CompilesLoops::compileForeach()`/`compileForelse()` after the loop expression, up to the alias. */
    private const PUSH_HEAD = ['$__env', '->', 'addLoop', '(', '$__currentLoopData', ')', ';', 'foreach', '(', '$__currentLoopData', 'as'];

    /** What follows `foreach(...):` and what a pop block ends with: the assignment the docblock attaches to. */
    private const PUSH_TAIL = ['$__env', '->', 'incrementLoopIndices', '(', ')', ';', '$loop', '=', '$__env', '->', 'getLastLoop', '(', ')', ';'];

    /** `CompilesLoops::compileEndforeach()`/`compileEmpty()` (bare). */
    private const POP = ['endforeach', ';', '$__env', '->', 'popLoop', '(', ')', ';', '$loop', '=', '$__env', '->', 'getLastLoop', '(', ')', ';'];

    /** @psalm-pure */
    public static function apply(string $compiled): string
    {
        if (!\str_contains($compiled, 'incrementLoopIndices')) {
            return $compiled;
        }

        $tokens = self::significantTokens($compiled);
        $depth = 0;
        /** @var array<int, int> $insertions byte offset right after a matched assignment => depth there */
        $insertions = [];

        foreach ($tokens as $index => $token) {
            if ($token[0] !== \T_OPEN_TAG) {
                continue;
            }

            $end = self::matchPush($tokens, $index + 1);

            if ($end !== null) {
                ++$depth;
            } else {
                $end = self::matchSequence($tokens, $index + 1, self::POP);

                if ($end === null) {
                    continue;
                }

                --$depth;
            }

            if ($depth < 0) {
                return $compiled;
            }

            if ($depth >= 2) {
                $last = $tokens[$end - 1];
                $insertions[$last[2] + \strlen($last[1])] = $depth;
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
     * `[$__empty_N = true;] $__currentLoopData = <expr>; addLoop(...); foreach(... as <alias>): <PUSH_TAIL>`.
     *
     * @param list<array{0: int|null, 1: string, 2: int}> $tokens
     *
     * @return int|null index just past the matched `getLastLoop();`, or null when the block is not a push
     *
     * @psalm-pure
     */
    private static function matchPush(array $tokens, int $at): ?int
    {
        if (
            isset($tokens[$at + 3])
            && \preg_match('/^\$__empty_\d+$/', $tokens[$at][1]) === 1
            && $tokens[$at + 1][1] === '='
            && $tokens[$at + 2][1] === 'true'
            && $tokens[$at + 3][1] === ';'
        ) {
            $at += 4;
        }

        $at = self::matchSequence($tokens, $at, ['$__currentLoopData', '=']);
        $at = $at === null ? null : self::skipToTerminator($tokens, $at, ';');
        $at = $at === null ? null : self::matchSequence($tokens, $at + 1, self::PUSH_HEAD);
        $at = $at === null ? null : self::skipToTerminator($tokens, $at, ')');
        $at = $at === null || ($tokens[$at + 1][1] ?? null) !== ':' ? null : $at + 2;

        return $at === null ? null : self::matchSequence($tokens, $at, self::PUSH_TAIL);
    }

    /**
     * @param list<array{0: int|null, 1: string, 2: int}> $tokens
     * @param list<string> $texts
     *
     * @return int|null index just past the last matched token
     *
     * @psalm-pure
     */
    private static function matchSequence(array $tokens, int $at, array $texts): ?int
    {
        foreach ($texts as $text) {
            if (($tokens[$at][1] ?? null) !== $text) {
                return null;
            }

            ++$at;
        }

        return $at;
    }

    /**
     * Index of the first `$terminator` token outside any bracket pair, starting at `$at`.
     *
     * @param list<array{0: int|null, 1: string, 2: int}> $tokens
     *
     * @psalm-pure
     */
    private static function skipToTerminator(array $tokens, int $at, string $terminator): ?int
    {
        $depth = 0;

        for ($count = \count($tokens); $at < $count; $at++) {
            [$id, $text] = $tokens[$at];

            if ($depth === 0 && $text === $terminator) {
                return $at;
            }

            if (in_array($text, ['(', '[', '{'], true) || $id === \T_CURLY_OPEN || $id === \T_DOLLAR_OPEN_CURLY_BRACES || $id === \T_ATTRIBUTE) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth < 0) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * `\stdClass&object{<fields>, parent: <frame one level up>}`, repeated $depth
     * times around the depth-1 frame's own `parent: object|null`.
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
     * Every token except whitespace and comments, with its byte offset.
     *
     * @return list<array{0: int|null, 1: string, 2: int}>
     *
     * @psalm-pure
     */
    private static function significantTokens(string $compiled): array
    {
        $tokens = [];
        $offset = 0;

        foreach (\token_get_all($compiled) as $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if (!in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $offset];
            }

            $offset += \strlen($text);
        }

        return $tokens;
    }
}

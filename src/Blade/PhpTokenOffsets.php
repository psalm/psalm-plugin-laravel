<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Where {@see \token_get_all()} found genuine PHP-mode constructs in a compiled shadow. A regex
 * match alone cannot tell compiler output from author text that merely looks like it (a comment or
 * string holding the same bytes); a byte offset PHP's own lexer reports as a token start can.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class PhpTokenOffsets
{
    /** Alternative-syntax openers: closed by `end<keyword>;`. `elseif`/`else` stay inside the open block. */
    private const BLOCK_KEYWORDS = [\T_IF => true, \T_FOREACH => true, \T_FOR => true, \T_WHILE => true, \T_SWITCH => true];

    private const BLOCK_CLOSERS = [\T_ENDIF => true, \T_ENDFOREACH => true, \T_ENDFOR => true, \T_ENDWHILE => true, \T_ENDSWITCH => true];

    /**
     * @return array{0: array<int, true>, 1: array<int, array{0: string, 1: int}>} start offsets of every
     *     `<?php`/`<?=` transition into PHP mode, and start offset => [text, block depth] of every doc
     *     comment. The depth counts open braces (blocks, closure bodies, `{$x}` interpolation) plus
     *     alternative-syntax blocks (`if (...):` ... `endif;`), so 0 means "straight-line top level".
     *     A malformed shadow can go negative, which a caller must treat as "not top level" too.
     *
     * @psalm-pure
     */
    public static function scan(string $compiled): array
    {
        $openTags = [];
        $docComments = [];
        $offset = 0;
        $depth = 0;
        // One frame per control keyword awaiting its `:`: [open parentheses, condition closed yet].
        /** @psalm-var list<array{0: int, 1: bool}> $pending */
        $pending = [];

        foreach (\token_get_all($compiled) as $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;
            // Single-character punctuation only: an encapsed-string or inline-HTML token can hold `}` too.
            $char = $id === null ? $text : null;

            if (in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                // Insignificant between a condition's `)` and its `:`.
            } elseif ($pending !== [] && $pending[\count($pending) - 1][1]) {
                \array_pop($pending);
                $depth += $char === ':' ? 1 : 0;
            }

            if ($id === \T_OPEN_TAG || $id === \T_OPEN_TAG_WITH_ECHO) {
                $openTags[$offset] = true;
            } elseif ($id === \T_DOC_COMMENT) {
                $docComments[$offset] = [$text, $depth];
            } elseif ($id === \T_CURLY_OPEN || $id === \T_DOLLAR_OPEN_CURLY_BRACES || $char === '{') {
                ++$depth;
            } elseif ($char === '}') {
                --$depth;
            } elseif ($id !== null && isset(self::BLOCK_CLOSERS[$id])) {
                --$depth;
            } elseif ($id !== null && isset(self::BLOCK_KEYWORDS[$id])) {
                $pending[] = [0, false];
            } elseif ($pending !== [] && $char === '(') {
                ++$pending[\count($pending) - 1][0];
            } elseif ($pending !== [] && $char === ')') {
                $top = \count($pending) - 1;
                --$pending[$top][0];
                $pending[$top][1] = $pending[$top][0] === 0;
            }

            $offset += \strlen($text);
        }

        return [$openTags, $docComments];
    }
}

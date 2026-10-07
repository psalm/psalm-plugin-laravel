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
     * @return array{0: array<int, int>, 1: array<int, array{0: string, 1: int, 2: bool}>, 2: array<int, true>}
     *     start offset => brace depth of every `<?php`/`<?=` transition into PHP mode; start offset
     *     => [text, block depth, starts a statement] of every doc comment; and the offsets of every
     *     `break`/`continue`/`goto`.
     *
     *     The block depth counts open braces (blocks, closure and function bodies, `{$x}`
     *     interpolation) plus alternative-syntax blocks (`if (...):` ... `endif;`), so 0 means
     *     "straight-line top level"; a malformed shadow can go negative, which a caller must treat
     *     as "not top level" too. A doc comment starts a statement only when the previous
     *     significant token is an open tag, `;` or `}`: after `)`, `else` or `do` it belongs to
     *     an unbraced conditional statement.
     *
     * @psalm-pure
     */
    public static function scan(string $compiled): array
    {
        $openTags = [];
        $docComments = [];
        $jumps = [];
        $offset = 0;
        $braces = 0;
        $alternative = 0;
        $previous = null;
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
                $alternative += $char === ':' ? 1 : 0;
            }

            if ($id === \T_OPEN_TAG || $id === \T_OPEN_TAG_WITH_ECHO) {
                $openTags[$offset] = $braces;
            } elseif ($id === \T_DOC_COMMENT) {
                $docComments[$offset] = [$text, $braces + $alternative, \in_array($previous, [\T_OPEN_TAG, ';', '}'], true)];
            } elseif ($id === \T_CURLY_OPEN || $id === \T_DOLLAR_OPEN_CURLY_BRACES || $char === '{') {
                ++$braces;
            } elseif ($char === '}') {
                --$braces;
            } elseif ($id !== null && isset(self::BLOCK_CLOSERS[$id])) {
                --$alternative;
            } elseif ($id !== null && isset(self::BLOCK_KEYWORDS[$id])) {
                $pending[] = [0, false];
            } elseif ($pending !== [] && $char === '(') {
                ++$pending[\count($pending) - 1][0];
            } elseif ($pending !== [] && $char === ')') {
                $top = \count($pending) - 1;
                --$pending[$top][0];
                $pending[$top][1] = $pending[$top][0] === 0;
            }

            if (\in_array($id, [\T_BREAK, \T_CONTINUE, \T_GOTO], true)) {
                $jumps[$offset] = true;
            }

            if (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $previous = $id ?? $char;
            }

            $offset += \strlen($text);
        }

        return [$openTags, $docComments, $jumps];
    }
}

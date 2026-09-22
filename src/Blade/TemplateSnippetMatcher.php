<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Decides whether a piece of a compiled shadow was written by the template author, by looking for
 * it in the raw template. Split out of {@see ShadowIssueRelocator} so the rule is testable against
 * plain strings, without a booted Psalm codebase to satisfy `CodeLocation::getSnippet()`.
 *
 * Blade rewrites the lines it compiles — `{{ $x->f(1) }}` becomes `<?php echo e($x->f(1)); ?>` —
 * so a whole shadow line is never author text. A call EXPRESSION is: the compiler wraps around it
 * and never inside it. Hence {@see self::callExpressionAt()}, which cuts the expression back out of
 * the shadow line so {@see self::occursIn()} has something the template can actually contain.
 *
 * @internal
 */
final class TemplateSnippetMatcher
{
    /** `BladeCompiler::compileComments()`'s pattern, for the default `{{`/`}}` content tags. */
    private const BLADE_COMMENT_PATTERN = '/\{\{--.*?--\}\}/s';

    /**
     * Whether $snippet appears in $source once both are collapsed to single-spaced text. A
     * multi-line snippet (a call spanning several lines) still matches this way; comparing
     * line-by-line instead would miss it and false-drop.
     *
     * Blade comments come out of $source first, because Blade itself removes them before it
     * compiles anything else: `f('a', {{-- why --}} 'b')` reaches the shadow as `f('a', 'b')`, so
     * leaving the comment in the template would make the author's own call unfindable.
     */
    public static function occursIn(string $snippet, string $source): bool
    {
        $source = (string) \preg_replace(self::BLADE_COMMENT_PATTERN, '', $source);

        return \str_contains(self::normalize($source), self::normalize($snippet));
    }

    /**
     * `name(...)` starting at $offset in $snippet, argument list included, or null when $offset
     * does not start such a call or the list is not closed within $snippet.
     *
     * Tokenizing rather than counting parentheses: a `)` inside a string literal or a comment must
     * not close the list, and PHP's own lexer is the only thing that gets every quoting form
     * (heredoc, interpolation, `#[...]`) right.
     *
     * Null is a decline, not "generated": the caller keeps the issue. Only a plain
     * identifier-then-arguments call is judged here, which is the shape Psalm reports
     * `TooManyArguments` on (its location is the callee-name node); a location pointing anywhere
     * else is not something this class can reason about.
     */
    public static function callExpressionAt(string $snippet, int $offset): ?string
    {
        if ($offset < 0 || $offset >= \strlen($snippet)) {
            return null;
        }

        try {
            // The tail is a fragment, so it routinely ends mid-construct; the plain (non-
            // TOKEN_PARSE) lexer tolerates that, but still warns on an unterminated string.
            $tokens = @\token_get_all('<?php ' . \substr($snippet, $offset));
        } catch (\Throwable) {
            return null;
        }

        \array_shift($tokens);

        $text = self::consumeCallee($tokens);

        return $text === null ? null : self::consumeArguments($tokens, $text);
    }

    /**
     * The callee name plus any whitespace up to its `(`, with both removed from $tokens; null when
     * the tokens do not start that way.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeCallee(array &$tokens): ?string
    {
        $head = \array_shift($tokens);

        if (!\is_array($head) || !\in_array($head[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $text = $head[1];

        while (($next = $tokens[0] ?? null) !== null && \is_array($next) && $next[0] === \T_WHITESPACE) {
            $text .= $next[1];
            \array_shift($tokens);
        }

        return ($tokens[0] ?? null) === '(' ? $text : null;
    }

    /**
     * $text extended with the balanced `(...)` the tokens open with; null when the list runs past
     * the end of the tokens, which happens whenever the call spans more lines than the snippet.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function consumeArguments(array $tokens, string $text): ?string
    {
        $depth = 0;

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                // Only a single-character token IS punctuation. A T_* token's TEXT can be a bare
                // `(` — `"$label("` lexes the tail of the string as T_ENCAPSED_AND_WHITESPACE `(`
                // — and counting that opens a level that never closes, so the scan runs one `)`
                // too far and swallows the enclosing `e(` the compiler wrote.
                $text .= $token[1];

                continue;
            }

            $text .= $token;

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')' && --$depth === 0) {
                return $text;
            }
        }

        return null;
    }

    /**
     * `callee(` plus everything up to $argEnd, where $argStart/$argEnd are the bounds of an
     * ARGUMENT inside $snippet, or null when the argument is not directly enclosed by a global,
     * unqualified call to $callee.
     *
     * The mirror image of {@see self::callExpressionAt()}, which reads forward from a callee-name
     * node. An argument-position issue (`PossiblyInvalidArgument` and friends) locates the argument
     * instead, and the enclosing callee sits BEFORE it: `CodeLocation` resets its preview start to
     * the beginning of the line, so the snippet always carries that preceding text.
     *
     * The slice deliberately stops at the argument's end rather than at the call's closing `)` —
     * `e(old('k')` is already enough to tell the compiler's wrapper from an author's own call, and
     * extending it would need a second tokenizer pass for nothing. It is a prefix, not a balanced
     * expression; the only consumer is {@see self::occursIn()}, a substring search.
     *
     * Null is a decline and the caller keeps the issue, so every unreadable shape fails open.
     */
    public static function enclosingCallAt(string $snippet, int $argStart, int $argEnd, string $callee): ?string
    {
        if ($argStart < 1 || $argEnd <= $argStart || $argEnd > \strlen($snippet)) {
            return null;
        }

        $index = $argStart - 1;

        // `echo` is a language construct: it precedes its argument directly, with no parenthesis in
        // between. Every other callee reported this way is an ordinary function call.
        if (\strcasecmp($callee, 'echo') !== 0) {
            $index = self::skipSpaceBack($snippet, $index);

            if ($index < 0 || $snippet[$index] !== '(') {
                return null;
            }

            $index--;
        }

        $end = self::skipSpaceBack($snippet, $index);
        $index = $end;

        while ($index >= 0 && self::isIdentifierChar($snippet[$index])) {
            $index--;
        }

        $start = $index + 1;

        // Case-insensitive because PHP identifiers are: an author's `{{ E(old('k')) }}` compiles to
        // an inner call that really is theirs, and declining on case alone would deny it the
        // template-source check that keeps it.
        if ($start > $end || \strcasecmp(\substr($snippet, $start, $end - $start + 1), $callee) !== 0) {
            return null;
        }

        // `\e(`, `Fx::e(` and `$obj->e(` all read backwards as the bare identifier `e`, and none of
        // them is the escape helper the compiler emits. (A preceding identifier character cannot
        // occur here — the scan above would have consumed it.)
        if ($start > 0 && \in_array($snippet[$start - 1], ['\\', '$', ':', '>'], true)) {
            return null;
        }

        return \substr($snippet, $start, $argEnd - $start);
    }

    /** The index of the last non-whitespace character at or before $index, or -1. */
    private static function skipSpaceBack(string $text, int $index): int
    {
        while ($index >= 0 && \ctype_space($text[$index])) {
            $index--;
        }

        return $index;
    }

    private static function isIdentifierChar(string $char): bool
    {
        return $char === '_' || \ctype_alnum($char) || \ord($char) >= 0x80;
    }

    private static function normalize(string $text): string
    {
        return \preg_replace('/\s+/', ' ', \trim($text)) ?? $text;
    }
}

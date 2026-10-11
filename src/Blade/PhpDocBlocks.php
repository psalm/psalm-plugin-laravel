<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Which doc comments of a tokenized PHP block are statement-level declarations of the template's own
 * scope: at nesting depth 0, directly after a statement boundary (the open tag, `;`, `}`, `?>` or
 * inline HTML), and not the docblock of a function, class, closure or attribute list.
 *
 * A property or method docblock, one inside a function body, and one inside an expression
 * (`[/** note *\/ 1]`) all carry a `@var` that is no view-data declaration, and Psalm does not apply
 * it to the template's scope either. {@see ContractParser} (what a template declares) and
 * {@see Annotate\TemplateAnnotator} (where a declaration is written) share this one definition.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class PhpDocBlocks
{
    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens `token_get_all()` output
     *
     * @return array<int, true> token index => true, for each statement-level doc comment
     *
     * @psalm-pure
     */
    public static function statementLevel(array $tokens): array
    {
        $found = [];
        $depth = 0;
        $boundary = true;

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                $depth = \max(0, $depth + (\strpbrk($token, '{([') !== false ? 1 : (\strpbrk($token, '})]') !== false ? -1 : 0)));
                $boundary = $depth === 0 && ($token === ';' || $token === '}');

                continue;
            }

            $id = $token[0];

            if ($id === \T_WHITESPACE || $id === \T_COMMENT) {
                continue;
            }

            if ($id === \T_DOC_COMMENT) {
                if ($boundary && $depth === 0 && \str_ends_with($token[1], '*/') && !self::attachesToDeclaration($tokens, $i + 1)) {
                    $found[$i] = true;
                }

                continue;
            }

            if (in_array($id, [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true)) {
                ++$depth;
            }

            $boundary = $depth === 0 && (in_array($id, [\T_OPEN_TAG, \T_CLOSE_TAG, \T_INLINE_HTML], true));
        }

        return $found;
    }

    /**
     * Whether the doc comment ending before `$from` documents a function, class or closure.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @psalm-pure
     */
    private static function attachesToDeclaration(array $tokens, int $from): bool
    {
        for ($i = $from, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                return false;
            }

            if (in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return \in_array($token[0], [
                \T_FUNCTION, \T_FN, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM,
                \T_ABSTRACT, \T_FINAL, \T_READONLY, \T_STATIC, \T_ATTRIBUTE,
            ], true);
        }

        return false;
    }
}

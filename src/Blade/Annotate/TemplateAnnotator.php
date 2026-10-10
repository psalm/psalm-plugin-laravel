<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\MarkerPrePass;
use Psalm\LaravelPlugin\Blade\SourceLines;

/**
 * Splices native `@var T $name` declarations into a Blade template's leading PHP block.
 *
 * A template's own docblock is the one place Psalm reads a `@var` as typing the body, and the
 * spelling a template author already knows. Where the lines go, in order of preference:
 *
 * 1. the first doc comment of a `<?php` block the file opens with — appended before its `*\/`, so
 *    the template keeps one docblock (a second one before the same statement is dropped by the PHP
 *    parser, which attaches only the last);
 * 2. a new `<?php /** ... *\/ ?>` block directly after that leading block, so a
 *    `declare(strict_types=1)` stays the first statement;
 * 3. the same new block at the very top of the file, after any BOM.
 *
 * Byte-conservative on purpose: the template is user source, so the only edit is one insertion at
 * one offset. Nothing is re-serialized, and every byte outside the inserted run comes out
 * identical — including a BOM, the file's own line endings, and any existing declaration, in
 * either spelling.
 *
 * Idempotency is derived from state, not from a marker: a name the source already declares is
 * dropped, so re-running over an annotated template inserts nothing.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class TemplateAnnotator
{
    private const BOM = "\u{FEFF}";

    /**
     * A `{{-- @var ... --}}` comment, and the line break that terminates it if there is one.
     * Group 1 is the inner content, which is what {@see ContractParser::VAR_PATTERN} reads.
     *
     * `\G`-anchored so it matches only at the offset {@see self::liveContractComments()} supplies: when
     * it fails at a live comment (not an `@var`, or multi-line), an unanchored search would slide
     * forward into dead text and read that instead.
     */
    private const CONTRACT_COMMENT = '/\G\{\{--(\s*@var\s[^\r\n]*?)--\}\}[^\S\r\n]*(?:\r?\n)?/';

    /**
     * @param array<string, string> $vars variable name (without `$`) => type string
     *
     * @return array{0: string, 1: int, 2: list<string>}|null the annotated source, the 1-based line
     *         the first inserted declaration lands on, and the declarations inserted; null when the
     *         template already declares every name
     *
     * @psalm-pure
     */
    public static function annotate(string $source, array $vars): ?array
    {
        $declared = self::declaredNames($source);
        $missing = \array_diff_key($vars, $declared);

        if ($missing === []) {
            return null;
        }

        \ksort($missing);

        $eol = self::dominantLineEnding($source);
        $bom = \str_starts_with($source, self::BOM) ? \strlen(self::BOM) : 0;
        $declarations = [];

        foreach ($missing as $name => $type) {
            $declarations[] = "@var {$type} \${$name}";
        }

        $header = self::leadingPhpBlock($source, $bom);

        if ($header !== null && $header['doc'] !== null) {
            [$offset, $insertion] = self::intoDocblock($header['doc'], $declarations, $eol);
        } else {
            $docblock = '/**' . $eol . ' * ' . \implode($eol . ' * ', $declarations) . $eol . ' */' . $eol;

            if ($header === null) {
                $offset = $bom;
                $insertion = '<?php' . $eol . $docblock . '?>' . $eol;
            } elseif ($header['closeEnd'] !== null) {
                $offset = $header['closeEnd'];
                $insertion = '<?php' . $eol . $docblock . '?>' . $eol;
            } else {
                // The file never leaves PHP, so a new PHP block would land before a `declare`;
                // a plain docblock after the open tag is legal there.
                $offset = $header['openEnd'];
                $insertion = $docblock;
            }
        }

        $prefix = \substr($source, 0, $offset);

        return [
            $prefix . $insertion . \substr($source, $offset),
            1 + SourceLines::breaksIn($prefix . \substr($insertion, 0, (int) \strpos($insertion, '@var'))),
            $declarations,
        ];
    }

    /**
     * Tokenized, so a `<?php` in text or a docblock-looking string is not mistaken for the header.
     *
     * @return array{doc: array{0: int, 1: string}|null, openEnd: int, closeEnd: int|null}|null null
     *         unless the file (after any BOM) opens with `<?php`; `doc` is the first doc comment's
     *         offset and text, `closeEnd` the offset after the block's `?>` (and the one line break
     *         PHP swallows after it), null when the block runs to the end of the file
     *
     * @psalm-pure
     */
    private static function leadingPhpBlock(string $source, int $start): ?array
    {
        if (\preg_match('/\G<\?php\s/i', $source, offset: $start) !== 1) {
            return null;
        }

        $block = ['doc' => null, 'openEnd' => $start, 'closeEnd' => null];

        // Tokenizing does not parse, so a syntactically broken block is fine; the @ is for the
        // warning an unterminated string emits.
        $tokens = @\token_get_all(\substr($source, $start));
        $offsets = [];
        $offset = $start;

        foreach ($tokens as $i => $token) {
            $offsets[$i] = $offset;
            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        $depth = 0;

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

                continue;
            }

            if ($token[0] === \T_CURLY_OPEN || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($token[0] === \T_OPEN_TAG) {
                $block['openEnd'] = $offsets[$i] + \strlen($token[1]);
            } elseif ($token[0] === \T_CLOSE_TAG) {
                $block['closeEnd'] = $offsets[$i] + \strlen($token[1]);

                break;
            } elseif ($token[0] === \T_DOC_COMMENT
                && $block['doc'] === null
                && $depth === 0
                && \str_ends_with($token[1], '*/')
                && !self::attachesToDeclaration($tokens, $i + 1)
            ) {
                $block['doc'] = [$offsets[$i], $token[1]];
            }
        }

        return $block;
    }

    /**
     * Whether the doc comment ending before `$from` documents a function, class or closure: a
     * `@var` there is not a variable declaration, and Psalm reports it as an unrecognised tag.
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

            if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT) {
                continue;
            }

            return \in_array($token[0], [
                \T_FUNCTION, \T_FN, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM,
                \T_ABSTRACT, \T_FINAL, \T_READONLY, \T_STATIC, \T_ATTRIBUTE,
            ], true);
        }

        return false;
    }

    /**
     * The lines go before the doc comment's closing delimiter, indented like it. A comment that closes
     * after text on its own line (a one-line docblock) is opened out instead: the new lines go in
     * front of the delimiter, which moves to a line of its own.
     *
     * @param array{0: int, 1: string} $doc offset and text of the doc comment
     * @param list<string> $declarations
     *
     * @return array{0: int, 1: string} the insertion offset and text
     *
     * @psalm-pure
     */
    private static function intoDocblock(array $doc, array $declarations, string $eol): array
    {
        [$offset, $text] = $doc;
        $head = \substr($text, 0, -2);
        $bare = \rtrim($head);

        if (\preg_match('/[\r\n]([ \t]*)\z/', $head, $closing, \PREG_OFFSET_CAPTURE) === 1) {
            [$indent, $lineStart] = $closing[1];

            return [$offset + $lineStart, $indent . '* ' . \implode($eol . $indent . '* ', $declarations) . $eol];
        }

        // Cut before the whitespace that preceded the delimiter, which then follows the new lines as
        // the delimiter's own indent.
        return [
            $offset + \strlen($bare),
            $eol . ' * ' . \implode($eol . ' * ', $declarations) . $eol . ($bare === $head ? ' ' : ''),
        ];
    }

    /**
     * Names the template already declares, in either spelling. Read straight from the source rather
     * than from the parsed contract: the writer must not re-declare a name that a raw PHP docblock
     * covers, and the typed contract never carries that spelling.
     *
     * @return array<string, true>
     *
     * @psalm-pure
     */
    private static function declaredNames(string $source): array
    {
        $declared = [];

        foreach (self::liveContractComments($source) as [, , $innerContent]) {
            $split = \preg_match(ContractParser::VAR_PATTERN, $innerContent, $matched) === 1
                ? ContractParser::splitVar($matched[1], true)
                : null;

            if ($split !== null) {
                $declared[$split[0]] = true;
            }
        }

        foreach (ContractParser::rawDeclaredNames($source) as $name) {
            $declared[$name] = true;
        }

        return $declared;
    }

    /**
     * CONTRACT_COMMENT matches at each top-level Blade comment, the only place {@see ContractParser}
     * reads a declaration. A `{{-- @var --}}` inside `@verbatim`, `@php`, raw PHP, or another comment
     * sits within a larger masked range, so it is literal text. Single-line comments only, like
     * CONTRACT_COMMENT itself (a multi-line `{{--\n@var ...\n--}}` is a known gap).
     *
     * @return list<array{0: string, 1: int, 2: string}> full match, byte offset, group 1
     *
     * @psalm-pure
     */
    private static function liveContractComments(string $source): array
    {
        $live = [];

        foreach (MarkerPrePass::maskedRanges($source) as [$text, $offset]) {
            if (
                \str_starts_with($text, '{{--')
                && \preg_match(self::CONTRACT_COMMENT, $source, $match, \PREG_OFFSET_CAPTURE, $offset) === 1
            ) {
                $live[] = [$match[0][0], $offset, $match[1][0]];
            }
        }

        return $live;
    }

    /**
     * A template mixing both endings keeps the one it uses more; a template with neither gets "\n".
     *
     * @psalm-pure
     */
    private static function dominantLineEnding(string $source): string
    {
        $crlf = \substr_count($source, "\r\n");

        return $crlf > \substr_count($source, "\n") - $crlf ? "\r\n" : "\n";
    }
}

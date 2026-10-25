<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\PhpDocBlocks;

/**
 * Splices native `@var T $name` declarations into a Blade template's leading PHP block.
 *
 * A template's own docblock is the one place Psalm reads a `@var` as typing the body, and the
 * spelling a template author already knows. Where the lines go, in order of preference:
 *
 * 1. the first doc comment of a `<?php` block the file opens with — appended before its `*\/`, so
 *    the template keeps one docblock (a second one before the same statement is dropped by the PHP
 *    parser, which attaches only the last). A one-line docblock is opened out first, so every tag
 *    sits on its own line. Never one carrying `@psalm-ignore-var`: Psalm drops its `@var` tags, so the
 *    lines would never type the template (nor would a rerun add them, the names then counting as
 *    declared); it takes 2 instead;
 * 2. a new `<?php /** ... *\/ ?>` block directly after that leading block, so a
 *    `declare(strict_types=1)` stays the first statement;
 * 3. the same new block at the very top of the file, after any BOM.
 *
 * Byte-conservative on purpose: the template is user source, so the only edit is one splice. Every
 * byte outside it comes out identical — including a BOM, the file's own line endings, and any
 * existing declaration, which at most moves to a line of its own.
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
     * @param array<string, string> $vars variable name (without `$`) => type string
     * @param array<string, true> $optional names a closed call site omits: declared `T|null $x = null`
     *
     * @return string|null the annotated source; null when the template already declares every name
     *
     * @throws \RuntimeException when the template's only possible header docblock ignores `@var` tags and
     *                           the leading block never closes, so no placement keeps both meanings
     *
     * @psalm-pure
     */
    public static function annotate(string $source, array $vars, array $optional = []): ?string
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
            // Psalm's type parser is pure in effect; it just is not annotated as such.
            /** @psalm-suppress ImpureMethodCall */
            $declarations[] = isset($optional[$name])
                ? '@var ' . ContractParser::withNull($type) . " \${$name} = null"
                : "@var {$type} \${$name}";
        }

        $header = self::leadingPhpBlock($source, $bom);
        $ignored = $header !== null && $header['doc'] !== null && \preg_match(ContractParser::IGNORE_VAR, $header['doc'][1]) === 1;

        if ($header !== null && $header['doc'] !== null && !$ignored) {
            [$offset, $docblock] = $header['doc'];
            $extended = self::intoDocblock($docblock, self::indentBefore($source, $offset), $declarations, $eol);

            return \substr_replace($source, $extended, $offset, \strlen($docblock));
        }

        $docblock = '/**' . $eol . ' * ' . \implode($eol . ' * ', $declarations) . $eol . ' */' . $eol;

        if ($header === null) {
            return \substr_replace($source, '<?php' . $eol . $docblock . '?>' . $eol, $bom, 0);
        }

        if ($header['closeEnd'] !== null) {
            return \substr_replace($source, '<?php' . $eol . $docblock . '?>' . $eol, $header['closeEnd'], 0);
        }

        // Before this docblock a new one would be the earlier of two and lose the statement to it; after
        // it, the statement's docblock would change meaning. A new `<?php` block would land before a `declare`.
        if ($ignored) {
            throw new \RuntimeException(
                'the template\'s header docblock carries @psalm-ignore-var and its PHP block never closes, so no declaration can be placed. Declare the variables by hand.',
            );
        }

        // The file never leaves PHP, so a new PHP block would land before a `declare`; a plain
        // docblock after the open tag is legal there.
        return \substr_replace($source, $docblock, $header['openEnd'], 0);
    }

    /**
     * Tokenized, so a `<?php` in text or a docblock-looking string is not mistaken for the header; the
     * docblock is the first statement-level one ({@see PhpDocBlocks}).
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

        $docblocks = PhpDocBlocks::statementLevel($tokens);

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === \T_OPEN_TAG) {
                $block['openEnd'] = $offsets[$i] + \strlen($token[1]);
            } elseif ($token[0] === \T_CLOSE_TAG) {
                $block['closeEnd'] = $offsets[$i] + \strlen($token[1]);

                break;
            } elseif ($block['doc'] === null && isset($docblocks[$i])) {
                $block['doc'] = [$offsets[$i], $token[1]];
            }
        }

        return $block;
    }

    /**
     * The docblock with the declarations appended before its `*\/`, indented like that delimiter.
     *
     * A one-line docblock is opened out first: text on the `/**` line and a `*\/` that follows text
     * each move to a line of their own, at the comment's column. Nothing else in the comment changes.
     *
     * @param list<string> $declarations
     *
     * @psalm-pure
     */
    private static function intoDocblock(string $docblock, string $indent, array $declarations, string $eol): string
    {
        $newLine = $eol . $indent . ' ';
        $docblock = (string) \preg_replace('/\A\/\*\*[ \t]*+(?![\r\n]|\*\/)/', '/**' . $newLine . '* ', $docblock);
        $docblock = (string) \preg_replace('/(?<=\S)[ \t]*\*\/\z/', $newLine . '*/', $docblock);

        // The `*/` now ends a line of its own; its indent is everything on that line before it.
        $closerLine = \strlen(\rtrim(\substr($docblock, 0, -2), " \t"));
        $closerIndent = \substr($docblock, $closerLine, -2);
        $lines = '';

        foreach ($declarations as $declaration) {
            $lines .= $closerIndent . '* ' . $declaration . $eol;
        }

        return \substr_replace($docblock, $lines, $closerLine, 0);
    }

    /**
     * The whitespace between the start of the line and `$offset`; empty when anything else is there.
     *
     * @psalm-pure
     */
    private static function indentBefore(string $source, int $offset): string
    {
        return \preg_match('/(?:\A|[\r\n])([ \t]*)\z/', \substr($source, 0, $offset), $match) === 1 ? $match[1] : '';
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
        return \array_fill_keys(ContractParser::rawDeclaredNames($source), true);
    }

    /**
     * The ending the template uses most, bare CR included; a tie or a template with none gets "\n".
     *
     * @psalm-pure
     */
    private static function dominantLineEnding(string $source): string
    {
        $crlf = \substr_count($source, "\r\n");
        $byEnding = [
            "\n" => \substr_count($source, "\n") - $crlf,
            "\r\n" => $crlf,
            "\r" => \substr_count($source, "\r") - $crlf,
        ];

        // arsort() is stable, so a tie keeps "\n" first.
        \arsort($byEnding);

        return \array_key_first($byEnding);
    }
}

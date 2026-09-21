<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractParser;

/**
 * Splices `{{-- @var T $name --}}` lines into a Blade template.
 *
 * Byte-conservative on purpose: the template is user source, so the only edit is one insertion of
 * whole lines at one offset. Nothing is re-serialized, and every byte outside the inserted run comes
 * out identical — including a BOM, the file's own line endings, and any existing declaration.
 *
 * Idempotency is derived from state, not from a marker: a name the source already declares is
 * dropped, so re-running over an annotated template inserts nothing.
 *
 * @internal
 */
final class TemplateAnnotator
{
    private const BOM = "\u{FEFF}";

    /**
     * A `{{-- @var ... --}}` comment, and the line break that terminates it if there is one.
     * Group 1 is the inner content, which is what {@see ContractParser::VAR_PATTERN} reads.
     */
    private const CONTRACT_COMMENT = '/\{\{--(\s*@var\s[^\r\n]*?)--\}\}[^\S\r\n]*(?:\r?\n)?/';

    /** A raw `<?php ... ?>` block, whose docblocks are the other spelling {@see ContractParser} does not read. */
    private const RAW_PHP_BLOCK = '/<\?php\b.*?(?:\?>|\z)/s';

    /**
     * The name a `@var` docblock binds inside a raw PHP block. Greedy within the line, to bind the
     * same (last) name {@see ContractParser::VAR_PATTERN} does; per line, because one block can hold
     * several docblocks and a pattern greedy across them would see only the last.
     */
    private const RAW_PHP_VAR = '/@var\s+[^\r\n]*\$(\w+)/';

    /**
     * @param array<string, string> $vars variable name (without `$`) => type string
     *
     * @return array{0: string, 1: int, 2: list<string>}|null the annotated source, the 1-based line
     *         the first inserted comment lands on, and the comments inserted; null when the template
     *         already declares every name
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
        [$offset, $unterminated] = self::insertionPoint($source);

        // A contract block the file ends on carries no line break of its own, so the insertion has
        // to supply the separator that would otherwise fuse two comments into one line.
        $prefix = \substr($source, 0, $offset) . ($unterminated ? $eol : '');
        $comments = [];
        $block = '';

        foreach ($missing as $name => $type) {
            $comments[] = "{{-- @var {$type} \${$name} --}}";
            $block .= "{{-- @var {$type} \${$name} --}}{$eol}";
        }

        return [
            $prefix . $block . \substr($source, $offset),
            1 + \substr_count($prefix, "\n"),
            $comments,
        ];
    }

    /**
     * Names the template already declares, in either spelling. Read straight from the source rather
     * than from the parsed contract: the writer must not re-declare a name that a raw PHP docblock
     * covers, and that spelling never reaches a {@see \Psalm\LaravelPlugin\Blade\ViewDataContract}.
     *
     * @return array<string, true>
     */
    private static function declaredNames(string $source): array
    {
        $declared = [];

        if (\preg_match_all(self::CONTRACT_COMMENT, $source, $comments) > 0) {
            foreach ($comments[1] as $innerContent) {
                if (\preg_match(ContractParser::VAR_PATTERN, $innerContent, $matched) === 1) {
                    $declared[$matched[2]] = true;
                }
            }
        }

        if (\preg_match_all(self::RAW_PHP_BLOCK, $source, $blocks) > 0) {
            foreach ($blocks[0] as $block) {
                if (\preg_match_all(self::RAW_PHP_VAR, $block, $names) > 0) {
                    foreach ($names[1] as $name) {
                        $declared[$name] = true;
                    }
                }
            }
        }

        return $declared;
    }

    /**
     * Directly after the last existing `{{-- @var --}}` comment, else the very top of the file.
     *
     * Appending to the existing run rather than opening a second one keeps a template's contract in
     * one place. Blade comments compile to nothing, so the top of the file is safe even when the
     * first directive is an `@extends`.
     *
     * @return array{0: int, 1: bool} the byte offset, and whether what precedes it is an existing
     *         declaration that carries no line break of its own
     */
    private static function insertionPoint(string $source): array
    {
        if (\preg_match_all(self::CONTRACT_COMMENT, $source, $matches, \PREG_OFFSET_CAPTURE) > 0) {
            /** @var array{0: string, 1: int} $last */
            $last = \end($matches[0]);

            return [$last[1] + \strlen($last[0]), !\str_ends_with($last[0], "\n")];
        }

        return [\str_starts_with($source, self::BOM) ? \strlen(self::BOM) : 0, false];
    }

    /** A template mixing both endings keeps the one it uses more; a template with neither gets "\n". */
    private static function dominantLineEnding(string $source): string
    {
        $crlf = \substr_count($source, "\r\n");

        return $crlf > \substr_count($source, "\n") - $crlf ? "\r\n" : "\n";
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\MarkerPrePass;
use Psalm\LaravelPlugin\Blade\SourceLines;

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
     * Matches raw source, so it also hits text the parser ignores; go through {@see self::liveContractComments()}.
     */
    private const CONTRACT_COMMENT = '/\{\{--(\s*@var\s[^\r\n]*?)--\}\}[^\S\r\n]*(?:\r?\n)?/';

    /**
     * @param array<string, string> $vars variable name (without `$`) => type string
     *
     * @return array{0: string, 1: int, 2: list<string>}|null the annotated source, the 1-based line
     *         the first inserted comment lands on, and the comments inserted; null when the template
     *         already declares every name
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
            1 + SourceLines::breaksIn($prefix),
            $comments,
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
            if (\preg_match(ContractParser::VAR_PATTERN, $innerContent, $matched) === 1) {
                $declared[$matched[2]] = true;
            }
        }

        foreach (ContractParser::rawDeclaredNames($source) as $name) {
            $declared[$name] = true;
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
     *
     * @psalm-pure
     */
    private static function insertionPoint(string $source): array
    {
        $comments = self::liveContractComments($source);

        if ($comments !== []) {
            [$text, $offset] = \end($comments);

            return [$offset + \strlen($text), !\str_ends_with($text, "\n")];
        }

        return [\str_starts_with($source, self::BOM) ? \strlen(self::BOM) : 0, false];
    }

    /**
     * CONTRACT_COMMENT matches that {@see ContractParser} would also read: those starting where a
     * top-level Blade comment does. A `{{-- @var --}}` inside `@verbatim`, `@php`, raw PHP, or
     * another comment sits within a larger masked range, so it is literal text, not a declaration.
     *
     * @return list<array{0: string, 1: int, 2: string}> full match, byte offset, group 1
     *
     * @psalm-pure
     */
    private static function liveContractComments(string $source): array
    {
        if (\preg_match_all(self::CONTRACT_COMMENT, $source, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $commentStarts = [];

        foreach (MarkerPrePass::maskedRanges($source) as [$text, $offset]) {
            if (\str_starts_with($text, '{{--')) {
                $commentStarts[$offset] = true;
            }
        }

        $live = [];

        foreach ($matches as $match) {
            if (isset($commentStarts[$match[0][1]])) {
                $live[] = [$match[0][0], $match[0][1], $match[1][0]];
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

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ProjectAnalyzer;

/**
 * A shadow issue's snippet with its selection as offsets into that snippet, so the string rules
 * in {@see TemplateSnippetMatcher} can run on plain text.
 *
 * The snippet runs to the end of the line holding the selection, however long that line is.
 * Psalm's own snippet stops 50 bytes past the selection on a line over 200 characters
 * ({@see CodeLocation::calculateRealLocation()}), which cuts a compiler-generated call
 * mid-arguments, and every rule that reads text forward of the selection would then decline
 * (#2127). A call whose `)` is on a later line than the selection end is not read, as on short lines.
 *
 * Offsets are not validated here: each rule bounds-checks what it reads and declines on its own.
 *
 * @internal
 */
final class ShadowSelection
{
    private function __construct(
        public readonly string $snippet,
        public readonly int $start,
        public readonly int $end,
    ) {}

    /**
     * Null on any Throwable: Psalm computes these lazily from file contents, and an unreadable
     * location is no evidence of anything, so every caller declines rather than crash or drop.
     */
    public static function of(CodeLocation $location): ?self
    {
        try {
            $snippet = $location->getSnippet();
            [$selectionStart, $selectionEnd] = $location->getSelectionBounds();
            [$snippetStart] = $location->getSnippetBounds();
        } catch (\Throwable) {
            return null;
        }

        return new self(
            self::throughLineEnd($location, $snippet, $snippetStart, $selectionEnd),
            $selectionStart - $snippetStart,
            $selectionEnd - $snippetStart,
        );
    }

    /**
     * Psalm's snippet, lengthened to the end of the selection's line when Psalm capped it short.
     * Falls back to Psalm's snippet on anything unexpected (unreadable file, a snippet that is not
     * a prefix of the recomputed text): a rule then sees what it always saw and declines.
     */
    private static function throughLineEnd(CodeLocation $location, string $snippet, int $snippetStart, int $selectionEnd): string
    {
        try {
            $contents = ProjectAnalyzer::getInstance()->getCodebase()->getFileContents($location->file_path);
            $lineEnd = \strpos($contents, "\n", $selectionEnd);
            $line = \substr($contents, $snippetStart, ($lineEnd === false ? \strlen($contents) : $lineEnd) - $snippetStart);
        } catch (\Throwable) {
            return $snippet;
        }

        return \strlen($line) > \strlen($snippet) && \str_starts_with($line, $snippet) ? $line : $snippet;
    }
}

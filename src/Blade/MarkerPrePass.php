<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Prepends a `<?php /* blade:N *\/ ?>` marker to (almost) every line of a
 * Blade template before compilation. Blade directives and interpolations
 * elide their own source lines from the compiled output, so LineMapBuilder
 * recovers the original line numbers by reading these markers back out.
 *
 * @internal
 */
final class MarkerPrePass
{
    /**
     * Byte-offset ranges of source that are not "live" Blade code: verbatim bodies,
     * `@php...@endphp` blocks, Blade comments, and raw `<?php ... ?>` / `<?= ... ?>`
     * tags. Shared by computeSkipLines() (these bodies get no per-line marker) and
     * extendsLine() (an `@extends` found inside one of these is not a live directive).
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function maskedRanges(string $source): array
    {
        // A single alternation, so an earlier-starting construct consumes its own body instead of
        // each pattern re-scanning the whole source independently: a raw PHP open tag typed inside
        // a Blade comment must not let the raw-PHP branch re-match past the comment's own close and
        // mask everything to EOF via the `.*\z` fallback below. Keep the comment branch before the
        // raw-PHP one. `(?i:php\b|=)` matches the case-insensitive PHP open tag; the `.*\z`
        // alternative covers a raw PHP block left unclosed at end of template, which Blade permits.
        $pattern = '/@verbatim.*?@endverbatim|@php.*?@endphp|\{\{--.*?--\}\}|<\?(?i:php\b|=)(?:.*?\?>|.*\z)/s';

        if (\preg_match_all($pattern, $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        /** @var list<array{0: string, 1: int}> $wholeMatches */
        $wholeMatches = $matches[0];

        return $wholeMatches;
    }

    /**
     * Lines that are pure continuations of a multi-line construct (verbatim
     * body, php-block body, blade comment body, raw-php body, multi-line echo
     * body, multi-line directive argument list body) get NO marker. The line
     * that OPENS the construct still gets a marker (state hasn't switched yet
     * when that line's marker decision is made).
     *
     * @return array<int, true>
     */
    public static function computeSkipLines(string $source): array
    {
        $patterns = [
            '/\{\{\{.*?\}\}\}/s',
            '/\{!!.*?!!\}/s',
            '/\{\{.*?\}\}/s',
            // Balanced-paren directive args via PCRE recursion; respects quoted strings.
            '/@[a-zA-Z_]+\s*(\((?:[^()\'"]|\'[^\']*\'|"[^"]*"|(?1))*\))/s',
            // Multi-line component tags. ComponentTagCompiler::compileOpeningTags()
            // matches attributes as a strict alternation separated by \s+; a marker
            // between two attributes matches no alternative and the whole tag is
            // silently left uncompiled as literal text. MANDATORY: never drop this.
            '/<\s*x[-:][\w\-:.]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*\/?>/s',
        ];

        $skip = [];
        $masked = self::maskedRanges($source);

        self::markSkipLines($source, $masked, $skip);

        // A lazy match (echo, directive args, component tags) can START inside a masked range and
        // run past its end into live source: e.g. a raw PHP block containing a literal `{{` with
        // no `}}` of its own would otherwise let the echo pattern consume every line up to the
        // next REAL `}}`. Scan a same-length copy with masked ranges blanked out (newlines kept,
        // so line numbers stay correct) instead of the original source.
        $scanSource = self::blankRanges($source, $masked);

        foreach ($patterns as $pattern) {
            if (\preg_match_all($pattern, $scanSource, $matches, \PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            /** @var list<array{0: string, 1: int}> $wholeMatches */
            $wholeMatches = $matches[0];

            self::markSkipLines($source, $wholeMatches, $skip);
        }

        return $skip;
    }

    /**
     * Replaces each given range with spaces, keeping newlines intact so line numbers and byte
     * offsets stay identical to $source.
     *
     * @param list<array{0: string, 1: int}> $ranges
     */
    private static function blankRanges(string $source, array $ranges): string
    {
        foreach ($ranges as [$text, $offset]) {
            $blanked = \preg_replace('/[^\n]/', ' ', $text);
            \assert($blanked !== null);

            $source = \substr_replace($source, $blanked, $offset, \strlen($text));
        }

        return $source;
    }

    /**
     * @param list<array{0: string, 1: int}> $matches
     * @param array<int, true> $skip
     */
    private static function markSkipLines(string $source, array $matches, array &$skip): void
    {
        foreach ($matches as [$text, $offset]) {
            $startLine = 1 + \substr_count($source, "\n", 0, $offset);
            $endLine = $startLine + \substr_count($text, "\n");

            for ($line = $startLine + 1; $line <= $endLine; $line++) {
                $skip[$line] = true;
            }
        }
    }

    /**
     * Whitespace-only lines get no marker: a marker there leaves `?>` right
     * before a newline, which PHP swallows when the compiled view executes.
     *
     * The trailing marker exists because Blade's addFooters() appends the
     * `@extends` footer after everything else, so without it the footer
     * would inherit the LAST content line's marker instead of the line the
     * `@extends` directive actually appears on.
     */
    public static function inject(string $source): string
    {
        $skip = self::computeSkipLines($source);
        $lines = \preg_split('/(?<=\n)/', $source);
        \assert($lines !== false);

        $out = '';
        $lineNumber = 1;

        foreach ($lines as $line) {
            if (!isset($skip[$lineNumber]) && \trim($line) !== '') {
                $out .= "<?php /* blade:{$lineNumber} */ ?>";
            }

            $out .= $line;
            $lineNumber++;
        }

        $extendsLine = self::extendsLine($source);

        if ($extendsLine !== null) {
            $out .= "<?php /* blade:{$extendsLine} */ ?>";
        }

        return $out;
    }

    /**
     * The source line of an `@extends`/`@extendsFirst` directive, if any. A match
     * inside a Blade comment, `@verbatim` body or raw PHP block is not a live
     * directive and is skipped in favor of the next candidate, if any.
     */
    public static function extendsLine(string $source): ?int
    {
        $matchCount = \preg_match_all('/@extends(First)?\s*\(/', $source, $matches, \PREG_OFFSET_CAPTURE);

        if ($matchCount === false || $matchCount === 0) {
            return null;
        }

        $masked = self::maskedRanges($source);

        /** @var list<array{0: string, 1: int}> $candidates */
        $candidates = $matches[0];

        foreach ($candidates as [, $offset]) {
            if (!self::isMasked($offset, $masked)) {
                return 1 + \substr_count($source, "\n", 0, $offset);
            }
        }

        return null;
    }

    /** @param list<array{0: string, 1: int}> $ranges */
    private static function isMasked(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$text, $rangeStart]) {
            if ($offset >= $rangeStart && $offset < $rangeStart + \strlen($text)) {
                return true;
            }
        }

        return false;
    }
}

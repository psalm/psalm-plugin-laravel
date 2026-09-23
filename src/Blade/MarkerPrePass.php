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
    // Comments and strings are indivisible: their parentheses never affect argument depth.
    private const ARGUMENT_PATTERN = <<<'REGEX'
    (?<args>\((?>\/\*.*?\*\/|\/\/[^\r\n]*|#(?!\[)[^\r\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|[^()'"\/#]|\/(?![\/*])|#(?=\[)|(?&args))*\))
    REGEX;

    /** Constructs Blade never compiles: their bodies reach the output as text, or not at all. */
    private const INERT_PATTERN = '/@verbatim.*?@endverbatim|\{\{--.*?--\}\}/s';

    /**
     * $source with Blade comments and `@verbatim` bodies replaced by spaces of equal length
     * (newlines kept, so offsets and line numbers stay identical). Blade strips comments before it
     * recognises directives, and emits a verbatim body as literal text, so neither can put a
     * directive or a variable read into the compiled output — a caller classifying a template from
     * its source must not read either.
     *
     * Deliberately narrower than {@see self::maskedRanges()}, which also masks `@php` and raw
     * `<?php` bodies: those DO execute, and a mention inside them is a real one.
     */
    public static function blankInertText(string $source): string
    {
        if (\preg_match_all(self::INERT_PATTERN, $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return $source;
        }

        /** @var list<array{0: string, 1: int}> $ranges */
        $ranges = $matches[0];

        return self::blankRanges($source, $ranges);
    }

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
        // Consume the earliest construct first: PHP-like text inside a Blade comment is inert.
        $pattern = '/@verbatim.*?@endverbatim|@php.*?@endphp|\{\{--.*?--\}\}|<\?(?i:php\b|=)/s';
        $ranges = [];
        $cursor = 0;
        while (\preg_match($pattern, $source, $match, \PREG_OFFSET_CAPTURE, $cursor) === 1) {
            [$text, $offset] = $match[0];
            if (\str_starts_with($text, '<?')) {
                $tokens = \token_get_all(\substr($source, $offset));
                $first = $tokens[0] ?? null;
                if (!\is_array($first) || ($first[0] !== \T_OPEN_TAG && $first[0] !== \T_OPEN_TAG_WITH_ECHO)) {
                    $cursor = $offset + \strlen($text);
                    continue;
                }

                $length = 0;
                foreach ($tokens as $token) {
                    if (\is_array($token) && $token[0] === \T_CLOSE_TAG) {
                        $length += \strlen(\rtrim($token[1], "\r\n"));
                        break;
                    }

                    $length += \strlen(\is_array($token) ? $token[1] : $token);
                }

                $text = \substr($source, $offset, $length);
            }

            $ranges[] = [$text, $offset];
            $cursor = $offset + \strlen($text);
        }

        return $ranges;
    }

    /**
     * Lines that are pure continuations of a multi-line construct (verbatim
     * body, php-block body, blade comment body, raw-php body, multi-line echo
     * body, multi-line directive argument list body) get NO marker. The line
     * that OPENS the construct still gets a marker (state hasn't switched yet
     * when that line's marker decision is made).
     *
     * @param list<array{0: string, 1: int}>|null $masked ranges already computed by the caller —
     *        {@see self::inject()} shares one scan with {@see self::extendsLine()} — or null to
     *        derive them here
     * @return array<int, true>
     */
    public static function computeSkipLines(string $source, ?array $masked = null): array
    {
        $masked ??= self::maskedRanges($source);

        $patterns = [
            '/\{\{\{.*?\}\}\}/s',
            '/\{!!.*?!!\}/s',
            '/\{\{.*?\}\}/s',
            '/@[a-zA-Z_]+\s*' . self::ARGUMENT_PATTERN . '/s',
            // Multi-line component tags. ComponentTagCompiler::compileOpeningTags()
            // matches attributes as a strict alternation separated by \s+; a marker
            // between two attributes matches no alternative and the whole tag is
            // silently left uncompiled as literal text. MANDATORY: never drop this.
            '/<\s*x[-:][\w\-:.]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*\/?>/s',
        ];

        $skip = [];

        self::markSkipLines($source, $masked, $skip);

        // A lazy match (echo, directive args, component tags) can START inside a masked range and
        // run past its end into live source: e.g. a raw PHP block containing a literal `{{` with
        // no `}}` of its own would otherwise let the echo pattern consume every line up to the
        // next REAL `}}`. Scan a same-length copy with masked ranges blanked out (newlines kept,
        // so line numbers stay correct) instead of the original source.
        $scanSource = self::blankRanges($source, $masked);

        self::markSwitchGapLines($source, $scanSource, $skip);

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
     * Blade's compileSwitch() opens PHP mode for the switch statement without closing it; PHP
     * mode stays open until the first @case closes it, since compileCase() relies on the
     * switch's still-open tag instead of opening its own. Every line from the @switch line's
     * successor through the @case line ITSELF therefore sits inside open PHP code, including the
     * @case line's own leading marker, so the whole span is gated (unlike every other gate in
     * this class, which skips continuation lines only and leaves the opening line marked).
     *
     * $firstCaseInSwitch in Laravel is a single bool, not a stack, so nesting is mirrored with a
     * single pending-line variable rather than a stack: an inner @switch re-arms it and the next
     * @case at ANY depth disarms it, matching the compiler exactly. A @switch with no following
     * @case leaves Blade's own output unterminated already; nothing is marked for it. Consequence:
     * the first @case line (and everything gated before it) inherits the @switch line's marker
     * via LineMapBuilder's carry-forward fallback, same as every other gated span.
     *
     * Walks $scanSource, not $source: compileStatements() dispatches directives via
     * `compile{$name}`, and PHP method names are case-insensitive, so `@SWITCH`/`@CASE` compile
     * identically to lowercase (hence the `i` modifier); and Blade strips its own comments
     * BEFORE directive recognition, so `@switch{{-- note --}}($x)` also compiles like `@switch($x)`
     * — the blanked scan source (masked ranges replaced with spaces) makes both of those visible
     * to `[ \t]*` while a masked directive simply can't match at all, so no separate isMasked()
     * guard is needed here. The argument is captured as a balanced-paren group (mirroring the
     * directive-arg pattern above) so literal directive-shaped text inside a quoted argument, e.g.
     * `@switch(str_contains($x, "@case(1)"))`, is consumed as part of the SAME match and can't be
     * mistaken for a real directive that arms or disarms the gate.
     *
     * Known limitation: a MULTI-line comment between the directive name and its `(` still isn't
     * recognized, because blanking preserves newlines and `[ \t]*` doesn't span them. Not chased
     * here; Blade joins it same as a single-line one.
     *
     * @param array<int, true> $skip
     */
    private static function markSwitchGapLines(string $source, string $scanSource, array &$skip): void
    {
        $pattern = '/(?<!@)@(switch|case)[ \t]*' . self::ARGUMENT_PATTERN . '/is';

        if (\preg_match_all($pattern, $scanSource, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        $pendingLine = null;

        // Ascending offsets (one pattern), so the source is scanned for newlines once in total.
        $cursor = 0;
        $line = 1;

        foreach ($matches[0] as $i => [, $offset]) {
            $name = \strtolower($matches[1][$i][0]);
            $line += \substr_count($source, "\n", $cursor, $offset - $cursor);
            $cursor = $offset;

            if ($name === 'switch') {
                $pendingLine = $line;

                continue;
            }

            if ($pendingLine !== null) {
                for ($l = $pendingLine + 1; $l <= $line; $l++) {
                    $skip[$l] = true;
                }

                $pendingLine = null;
            }
        }
    }

    /**
     * Lines inside a masked `@php` or raw `<?php`/`<?=` body that can carry a BARE marker comment:
     * PHP mode is open where the line starts, and no token that opened on an earlier line is still
     * running there. Those bodies reach the shadow byte-for-byte (Blade wraps `@php` in
     * `<?php ... ?>` and passes raw tags through untouched), so a comment placed at the head of such
     * a line is the only thing between the line and its own statement.
     *
     * Everything else in the block keeps inheriting the nearest preceding mapped line: a heredoc or
     * nowdoc body, a multi-line string, a block comment's interior, and anything past a `?>` would
     * swallow the marker as text instead of reading it as one — the second case turns the shadow
     * into text that no longer parses.
     *
     * Tokenized per range with the opener rewritten to `<?php ` and glued on with NO newline, so the
     * lexer's line 1 is the template line the block opens on. `T_OPEN_TAG`/`T_OPEN_TAG_WITH_ECHO`
     * carry their own trailing newline, and `T_CLOSE_TAG` can too, so neither counts as covering the
     * line it ends on; without the open-tag exemption the FIRST body line loses its marker.
     *
     * @param list<array{0: string, 1: int}> $masked ranges from {@see self::maskedRanges()}
     * @return array<int, true>
     */
    private static function safeBlockLines(string $source, array $masked): array
    {
        $safe = [];

        // Ascending offsets, so the source is scanned for newlines once in total.
        $cursor = 0;
        $openLine = 1;

        foreach ($masked as [$text, $offset]) {
            $openLine += \substr_count($source, "\n", $cursor, $offset - $cursor);
            $cursor = $offset;

            $openerLength = self::phpOpenerLength($text);

            if ($openerLength === null) {
                continue;
            }

            $line = $openLine;
            $inPhp = false;

            // The suppression mirrors callExpressionAt(): a block cut off at EOF ends mid-construct, and
            // the lexer warns on an unterminated string while still returning usable tokens.
            // A SPACE, never a newline: `<?php` is only an open tag when whitespace follows it, and
            // `<?=` is the one opener a body can follow with nothing in between — `<?=<<<'TXT'`
            // glued bare yields `<?php<<<'TXT'`, which lexes as inline HTML, so a `<?php` written
            // inside that nowdoc becomes the tag that opens PHP mode and the quoted body is judged
            // markable. A newline would fix that too, and would break line 1's anchor to the
            // opener's own template line.
            foreach (@\token_get_all('<?php ' . \substr($text, $openerLength)) as $token) {
                $id = \is_array($token) ? $token[0] : null;
                $newlines = \substr_count(\is_array($token) ? $token[1] : $token, "\n");

                if ($id === \T_OPEN_TAG || $id === \T_OPEN_TAG_WITH_ECHO) {
                    $inPhp = true;
                } elseif ($id === \T_CLOSE_TAG) {
                    $inPhp = false;
                }

                // These four end AT a line boundary instead of running past one, so each line they
                // reach still starts clean. Every other multi-line token is still open there.
                $endsCleanly = \in_array($id, [\T_WHITESPACE, \T_OPEN_TAG, \T_OPEN_TAG_WITH_ECHO, \T_CLOSE_TAG], true);

                if ($inPhp && $endsCleanly) {
                    for ($l = $line + 1; $l <= $line + $newlines; $l++) {
                        $safe[$l] = true;
                    }
                }

                $line += $newlines;
            }
        }

        return $safe;
    }

    /**
     * Byte length of the PHP-block opener a masked range starts with, or null when the range is a
     * `@verbatim` body or a Blade comment instead — neither reaches the shadow as PHP.
     */
    private static function phpOpenerLength(string $text): ?int
    {
        if (\str_starts_with($text, '@php')) {
            return 4;
        }

        return \preg_match('/^<\?(?:php\b|=)/i', $text, $match) === 1 ? \strlen($match[0]) : null;
    }

    /**
     * Replaces each given range with spaces, keeping newlines intact so line numbers and byte
     * offsets stay identical to $source.
     *
     * @param list<array{0: string, 1: int}> $ranges
     */
    private static function blankRanges(string $source, array $ranges): string
    {
        $out = '';
        $cursor = 0;

        foreach ($ranges as [$text, $offset]) {
            $blanked = \preg_replace('/[^\n]/', ' ', $text);
            \assert($blanked !== null);

            $out .= \substr($source, $cursor, $offset - $cursor) . $blanked;
            $cursor = $offset + \strlen($text);
        }

        return $out . \substr($source, $cursor);
    }

    /**
     * @param list<array{0: string, 1: int}> $matches
     * @param array<int, true> $skip
     */
    private static function markSkipLines(string $source, array $matches, array &$skip): void
    {
        // Ascending offsets (one pattern), so the source is scanned for newlines once in total.
        $cursor = 0;
        $startLine = 1;

        foreach ($matches as [$text, $offset]) {
            $startLine += \substr_count($source, "\n", $cursor, $offset - $cursor);
            $cursor = $offset;
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
     * A skipped line inside a `@php` or raw PHP body gets a BARE marker
     * instead ({@see self::safeBlockLines()}) — PHP mode is already open there,
     * so the `<?php ?>` wrapper would be a syntax error, while the comment on
     * its own reads back identically ({@see LineMapBuilder} matches T_COMMENT
     * regardless of the tags around it). Every other skipped line still gets
     * nothing and inherits the line that opened its construct.
     *
     * The trailing marker exists because Blade's addFooters() appends the
     * `@extends` footer after everything else, so without it the footer
     * would inherit the LAST content line's marker instead of the line the
     * `@extends` directive actually appears on.
     */
    public static function inject(string $source, string $markerPrefix = 'blade:'): string
    {
        $masked = self::maskedRanges($source);
        $skip = self::computeSkipLines($source, $masked);
        $safe = self::safeBlockLines($source, $masked);
        $lines = SourceLines::split($source);

        $out = '';
        $lineNumber = 1;

        foreach ($lines as $line) {
            if (\trim($line) !== '') {
                if (!isset($skip[$lineNumber])) {
                    $out .= "<?php /* {$markerPrefix}{$lineNumber} */ ?>";
                } elseif (isset($safe[$lineNumber])) {
                    $out .= "/* {$markerPrefix}{$lineNumber} */ ";
                }
            }

            $out .= $line;
            $lineNumber++;
        }

        $extendsLine = self::extendsLine($source, $masked);

        if ($extendsLine !== null) {
            $out .= "<?php /* {$markerPrefix}{$extendsLine} */ ?>";
        }

        return $out;
    }

    /**
     * The source line of an `@extends`/`@extendsFirst` directive, if any. A match
     * inside a Blade comment, `@verbatim` body or raw PHP block is not a live
     * directive and is skipped in favor of the next candidate, if any.
     *
     * @param list<array{0: string, 1: int}>|null $masked as in {@see self::computeSkipLines()};
     *        a template with no `@extends` at all never needs them
     */
    public static function extendsLine(string $source, ?array $masked = null): ?int
    {
        $matchCount = \preg_match_all('/@extends(?:First)?\s*\(/', $source, $matches, \PREG_OFFSET_CAPTURE);

        if ($matchCount === false || $matchCount === 0) {
            return null;
        }

        $masked ??= self::maskedRanges($source);

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

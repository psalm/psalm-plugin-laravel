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
     * Lines that are pure continuations of a multi-line construct (verbatim
     * body, php-block body, blade comment body, multi-line echo body,
     * multi-line directive argument list body) get NO marker. The line that
     * OPENS the construct still gets a marker (state hasn't switched yet
     * when that line's marker decision is made).
     *
     * @return array<int, true>
     */
    public static function computeSkipLines(string $source): array
    {
        $patterns = [
            '/@verbatim.*?@endverbatim/s',
            '/@php.*?@endphp/s',
            '/\{\{--.*?--\}\}/s',
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

        foreach ($patterns as $pattern) {
            if (\preg_match_all($pattern, $source, $matches, \PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            /** @var list<array{0: string, 1: int}> $wholeMatches */
            $wholeMatches = $matches[0];

            foreach ($wholeMatches as [$text, $offset]) {
                $startLine = 1 + \substr_count($source, "\n", 0, $offset);
                $endLine = $startLine + \substr_count($text, "\n");

                for ($line = $startLine + 1; $line <= $endLine; $line++) {
                    $skip[$line] = true;
                }
            }
        }

        return $skip;
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

    /** The source line of an `@extends`/`@extendsFirst` directive, if any. */
    public static function extendsLine(string $source): ?int
    {
        if (\preg_match('/@extends(First)?\s*\(/', $source, $match, \PREG_OFFSET_CAPTURE)) {
            return 1 + \substr_count($source, "\n", 0, $match[0][1]);
        }

        return null;
    }
}

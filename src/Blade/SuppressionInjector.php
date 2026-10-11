<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Carries `{{-- @psalm-suppress X --}}` Blade comments into the shadow file.
 * Blade compiles comments away entirely, so the suppression has to be
 * re-attached to the first real statement that follows it, inside the SAME
 * php block a separate `<?php ?>` block does not suppress anything.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class SuppressionInjector
{
    /**
     * @param array<int, int> $lineMap shadow line => blade source line (0 = prelude)
     *
     * @psalm-mutation-free
     */
    public function inject(string $shadowContent, string $bladeSource, array $lineMap, string $markerPrefix = 'blade:'): string
    {
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return $shadowContent;
        }

        $targets = $this->findTargets($shadowContent, $suppressions, $lineMap, $markerPrefix);

        if ($targets === []) {
            return $shadowContent;
        }

        foreach (\array_reverse($targets) as $target) {
            $shadowContent = \substr_replace($shadowContent, ' /** @psalm-suppress ' . \implode(', ', $target['rules']) . ' */', $target['offset'], 0);
        }

        return $shadowContent;
    }

    /**
     * The same suppressions keyed by the TEMPLATE line of the statement they attach to, for the
     * issue remap: a relocated issue lands on that line, and Psalm's own docblock suppression
     * inside the shadow only covers issues it raises at that statement.
     *
     * @param array<int, int> $lineMap shadow line => blade source line (0 = prelude)
     *
     * @return array<int, list<string>> blade line => suppressed rules
     *
     * @psalm-mutation-free
     */
    public function resolve(string $shadowContent, string $bladeSource, array $lineMap, string $markerPrefix = 'blade:'): array
    {
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return [];
        }

        $resolved = [];

        foreach ($this->findTargets($shadowContent, $suppressions, $lineMap, $markerPrefix) as $target) {
            $bladeLine = $lineMap[$target['line']] ?? 0;
            $resolved[$bladeLine] = [...$resolved[$bladeLine] ?? [], ...$target['rules']];
        }

        return $resolved;
    }


    /**
     * Splits the body of one `@psalm-suppress` tag into its issue names, accepting the same
     * comma-separated form (`A, B`) and trailing description Psalm's own docblock parser does.
     *
     * @return list<string>
     * @psalm-pure
     */
    public static function parseRuleList(string $tagBody): array
    {
        if (\preg_match('/^\s*([A-Za-z0-9_-]+(?:\s*,\s*[A-Za-z0-9_-]+)*)/', $tagBody, $matches) !== 1) {
            return [];
        }

        return \array_map(trim(...), \explode(',', $matches[1]));
    }

    /**
     * @param array<int, list<string>> $suppressions blade line => suppressed rules
     * @param array<int, int> $lineMap
     * @return list<array{line: int, offset: int, rules: list<string>}> one entry per open tag, so
     *     several suppression comments aimed at one statement share a single docblock (Psalm reads
     *     only the docblock nearest the statement)
     */
    private function findTargets(string $content, array $suppressions, array $lineMap, string $markerPrefix): array
    {
        $openTags = [];
        $offset = 0;
        $tokens = \token_get_all($content);
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token) && ($token[0] === \T_OPEN_TAG || $token[0] === \T_OPEN_TAG_WITH_ECHO)
                && !$this->opensAMarkerOnlyBlock($tokens, $index, $markerPrefix)
            ) {
                $openTags[] = ['line' => $token[2], 'offset' => $offset + \strlen(\rtrim($text))];
            }

            $offset += \strlen($text);
        }

        $targets = [];
        foreach ($suppressions as $bladeLine => $rules) {
            foreach ($openTags as $openTag) {
                if (($lineMap[$openTag['line']] ?? 0) > $bladeLine) {
                    $existing = $targets[$openTag['offset']]['rules'] ?? [];
                    $targets[$openTag['offset']] = [...$openTag, 'rules' => \array_values(\array_unique([...$existing, ...$rules]))];
                    break;
                }
            }
        }

        return \array_values($targets);
    }

    /**
     * Whether the open tag at $index is one of {@see MarkerPrePass}'s own `<?php /* N *\/ ?>`
     * blocks, which carries no statement for a suppression docblock to attach to.
     *
     * The closing tag is part of the test, not decoration: since #1544 an author's own multi-line
     * `<?php` block ALSO opens with a marker comment, one that is followed by their code rather
     * than by `?>`. Matching on the marker alone would exclude that block and push every
     * suppression aimed at it onto some later statement.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @psalm-pure
     */
    private function opensAMarkerOnlyBlock(array $tokens, int $index, string $markerPrefix): bool
    {
        if (MarkerComment::sourceLine($tokens[$index + 1] ?? '', $markerPrefix) === null) {
            return false;
        }

        $next = $tokens[$index + 2] ?? null;

        if (\is_array($next) && $next[0] === \T_WHITESPACE) {
            $next = $tokens[$index + 3] ?? null;
        }

        return \is_array($next) && $next[0] === \T_CLOSE_TAG;
    }

    /**
     * @return array<int, list<string>> blade line => suppressed rules
     *
     * @psalm-pure
     */
    private function findSuppressions(string $bladeSource): array
    {
        $suppressions = [];

        // Unanchored: PCRE's `^`/`$` only know `\n`, so under bare-CR endings an anchored `^.*` would
        // swallow every comment but the last on a run of CR-separated lines.
        if (\preg_match_all('/\{\{--\s*@psalm-suppress\s+(.+?)\s*--\}\}/', $bladeSource, $matches, \PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[1] as [$ruleList, $offset]) {
                $rules = self::parseRuleList($ruleList);

                if ($rules !== []) {
                    $line = 1 + SourceLines::breaksIn($bladeSource, 0, $offset);
                    $suppressions[$line] = $rules;
                }
            }
        }

        return $suppressions;
    }
}

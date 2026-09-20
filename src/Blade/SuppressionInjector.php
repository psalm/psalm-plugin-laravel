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
 */
final class SuppressionInjector
{
    /**
     * @param array<int, int> $lineMap shadow line => blade source line (0 = prelude)
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
            $shadowContent = \substr_replace($shadowContent, ' /** @psalm-suppress ' . $target['rule'] . ' */', $target['offset'], 0);
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
     */
    public function resolve(string $shadowContent, string $bladeSource, array $lineMap, string $markerPrefix = 'blade:'): array
    {
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return [];
        }

        $resolved = [];

        foreach ($this->findTargets($shadowContent, $suppressions, $lineMap, $markerPrefix) as $target) {
            $resolved[$lineMap[$target['line']] ?? 0][] = $target['rule'];
        }

        return $resolved;
    }

    /**
     * Every rule suppressed anywhere in the template source, independent of whether a following PHP
     * statement exists to attach a docblock to. A static-HTML-only template has no such statement
     * (`findTargets()` drops the suppression for nothing to attach to), so a FILE-LEVEL issue with no
     * call site of its own — {@see \Psalm\LaravelPlugin\Issues\UnusedView} — reads this instead of
     * the target-keyed map {@see self::resolve()} builds.
     *
     * @return list<string>
     */
    public function suppressedRules(string $bladeSource): array
    {
        return \array_values($this->findSuppressions($bladeSource));
    }

    /**
     * @param array<int, string> $suppressions blade line => suppressed rule
     * @param array<int, int> $lineMap
     * @return list<array{line: int, offset: int, rule: string}>
     */
    private function findTargets(string $content, array $suppressions, array $lineMap, string $markerPrefix): array
    {
        $openTags = [];
        $offset = 0;
        $tokens = \token_get_all($content);
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token) && ($token[0] === \T_OPEN_TAG || $token[0] === \T_OPEN_TAG_WITH_ECHO)
                && MarkerComment::sourceLine($tokens[$index + 1] ?? '', $markerPrefix) === null
            ) {
                $openTags[] = ['line' => $token[2], 'offset' => $offset + \strlen(\rtrim($text))];
            }

            $offset += \strlen($text);
        }

        $targets = [];
        foreach ($suppressions as $bladeLine => $rule) {
            foreach ($openTags as $openTag) {
                if (($lineMap[$openTag['line']] ?? 0) > $bladeLine) {
                    $targets[] = [...$openTag, 'rule' => $rule];
                    break;
                }
            }
        }

        return $targets;
    }

    /** @return array<int, string> blade line => suppressed rule */
    private function findSuppressions(string $bladeSource): array
    {
        $suppressions = [];

        if (\preg_match_all('/^.*\{\{--\s*@psalm-suppress\s+(\S+)\s*--\}\}.*$/m', $bladeSource, $matches, \PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $index => [, $offset]) {
                $line = 1 + \substr_count($bladeSource, "\n", 0, $offset);
                $suppressions[$line] = $matches[1][$index][0];
            }
        }

        return $suppressions;
    }
}

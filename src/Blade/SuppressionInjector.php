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
    public function inject(string $shadowContent, string $bladeSource, array $lineMap): string
    {
        $lines = \preg_split('/(?<=\n)/', $shadowContent);
        \assert($lines !== false);

        $targets = $this->findTargets($lines, $bladeSource, $lineMap);

        if ($targets === []) {
            return $shadowContent;
        }

        foreach ($targets as $target) {
            $lines[$target['index']] = $this->insertAfterOpenTag($lines[$target['index']], $target['rule']);
        }

        return \implode('', $lines);
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
    public function resolve(string $shadowContent, string $bladeSource, array $lineMap): array
    {
        $lines = \preg_split('/(?<=\n)/', $shadowContent);
        \assert($lines !== false);

        $resolved = [];

        foreach ($this->findTargets($lines, $bladeSource, $lineMap) as $target) {
            $resolved[$lineMap[$target['index'] + 1] ?? 0][] = $target['rule'];
        }

        return $resolved;
    }

    /**
     * @param list<string>    $lines
     * @param array<int, int> $lineMap
     *
     * @return list<array{index: int, rule: string}> shadow line index (0-based) => suppressed rule
     */
    private function findTargets(array $lines, string $bladeSource, array $lineMap): array
    {
        $targets = [];

        foreach ($this->findSuppressions($bladeSource) as $bladeLine => $rule) {
            $targetIndex = $this->findTargetLine($lines, $lineMap, $bladeLine);

            if ($targetIndex === null) {
                continue; // no following statement — nothing to attach to
            }

            $targets[] = ['index' => $targetIndex, 'rule' => $rule];
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

    /**
     * @param list<string> $lines
     * @param array<int, int> $lineMap
     */
    private function findTargetLine(array $lines, array $lineMap, int $afterBladeLine): ?int
    {
        foreach ($lines as $index => $line) {
            $shadowLine = $index + 1;
            $bladeLine = $lineMap[$shadowLine] ?? 0;

            if ($bladeLine <= $afterBladeLine) {
                continue;
            }

            // The negative lookahead skips the marker's own `<?php`, which is
            // always immediately followed by ` /* blade:N */`.
            if (\preg_match('/<\?(php|=)(?! \/\* blade:\d+ \*\/)/', $line) === 1) {
                return $index;
            }
        }

        return null;
    }

    private function insertAfterOpenTag(string $line, string $rule): string
    {
        return (string) \preg_replace(
            '/<\?(php|=)(?! \/\* blade:\d+ \*\/)/',
            "<?\$1 /** @psalm-suppress {$rule} */",
            $line,
            1,
        );
    }
}

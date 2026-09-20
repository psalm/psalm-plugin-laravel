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
     * A shadow's own `<?php`/`<?=` open tag. The negative lookahead skips a marker's open tag,
     * which is always immediately followed by ` /* blade:N *\/`.
     */
    private const OPEN_TAG_PATTERN = '/<\?(php|=)(?! \/\* blade:\d+ \*\/)/';

    /**
     * @param array<int, int> $lineMap shadow line => blade source line (0 = prelude)
     */
    public function inject(string $shadowContent, string $bladeSource, array $lineMap): string
    {
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return $shadowContent;
        }

        $lines = SourceLines::split($shadowContent);
        $targets = $this->findTargets($lines, $suppressions, $lineMap);

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
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return [];
        }

        $lines = SourceLines::split($shadowContent);
        $resolved = [];

        foreach ($this->findTargets($lines, $suppressions, $lineMap) as $target) {
            $resolved[$lineMap[$target['index'] + 1] ?? 0][] = $target['rule'];
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
     * @param list<string>      $lines
     * @param array<int, string> $suppressions blade line => suppressed rule
     * @param array<int, int>   $lineMap
     *
     * @return list<array{index: int, rule: string}> shadow line index (0-based) => suppressed rule
     */
    private function findTargets(array $lines, array $suppressions, array $lineMap): array
    {
        $targets = [];

        foreach ($suppressions as $bladeLine => $rule) {
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

            if (\preg_match(self::OPEN_TAG_PATTERN, $line) === 1) {
                return $index;
            }
        }

        return null;
    }

    private function insertAfterOpenTag(string $line, string $rule): string
    {
        return (string) \preg_replace(
            self::OPEN_TAG_PATTERN,
            "<?\$1 /** @psalm-suppress {$rule} */",
            $line,
            1,
        );
    }
}

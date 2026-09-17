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
        $suppressions = $this->findSuppressions($bladeSource);

        if ($suppressions === []) {
            return $shadowContent;
        }

        $lines = \preg_split('/(?<=\n)/', $shadowContent);
        \assert($lines !== false);

        foreach ($suppressions as $bladeLine => $rule) {
            $targetIndex = $this->findTargetLine($lines, $lineMap, $bladeLine);

            if ($targetIndex === null) {
                continue; // no following statement — nothing to attach to
            }

            $lines[$targetIndex] = $this->insertAfterOpenTag($lines[$targetIndex], $rule);
        }

        return \implode('', $lines);
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

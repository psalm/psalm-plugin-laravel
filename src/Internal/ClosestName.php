<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * "Did you mean" lookup for diagnostics that reject a name against a known set.
 *
 * @internal
 * @psalm-immutable
 */
final class ClosestName
{
    /**
     * The candidate nearest to $name by Levenshtein distance, or null when none is close enough
     * to read as a typo. The threshold scales with the name (one edit per three characters, at
     * least one), so a short name never suggests an unrelated one.
     *
     * @param list<string> $candidates
     * @psalm-pure
     */
    public static function find(string $name, array $candidates): ?string
    {
        $maxDistance = \max(1, \intdiv(\strlen($name), 3));
        $best = null;
        $bestDistance = $maxDistance + 1;

        foreach ($candidates as $candidate) {
            $distance = \levenshtein($name, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\CodeLocation\Raw;

/**
 * Rewrites a taint issue's journey so every hop that happened inside a compiled shadow reads as a
 * hop through the Blade template. Without it a relocated taint issue points at the template while
 * its own trace walks a `.cache/` path the user never wrote.
 *
 * Journey and journey text are remapped separately on purpose. The journey ARRAY stops at the
 * source chain, while the TEXT also spells out the sink-side nodes, so the text cannot be
 * regenerated from the array and is rewritten by substituting location descriptors in place.
 *
 * Pure: every shadow lookup arrives through the resolver, so the whole remap is exercisable
 * without a live Psalm run.
 *
 * @internal
 */
final class JourneyRemapper
{
    /**
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     * @param CodeLocation                   $issueLocation the issue's own (shadow) location, which
     *                                                      `journey_text` can name even when no
     *                                                      journey step does
     * @param \Closure(string): ?ShadowTarget $resolve       shadow path => target; null for every
     *                                                      path that is not a registered shadow
     *
     * @return array{journey: list<array{location: ?CodeLocation, label: string, entry_path_type: string}>, journey_text: string}|null
     *                                                      null declines: a step landed in a shadow
     *                                                      whose template has no such line, and a
     *                                                      half-remapped journey is worse than none
     */
    public static function remap(
        array $journey,
        string $journeyText,
        CodeLocation $issueLocation,
        \Closure $resolve,
    ): ?array {
        $targets = self::resolveTargets($journey, $issueLocation, $resolve);

        if ($targets === []) {
            return ['journey' => $journey, 'journey_text' => $journeyText];
        }

        $remapped = self::remapSteps($journey, $targets);

        if ($remapped === null) {
            return null;
        }

        $text = self::rewriteText($journeyText, $targets);

        return $text === null ? null : ['journey' => $remapped, 'journey_text' => $text];
    }

    /**
     * Shadows named anywhere in the issue, keyed by the file NAME their descriptors use — which is
     * what `journey_text` prints, while the journey array carries full paths.
     *
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     * @param \Closure(string): ?ShadowTarget                                              $resolve
     *
     * @return array<string, array{path: string, target: ShadowTarget}> file name => shadow
     */
    private static function resolveTargets(array $journey, CodeLocation $issueLocation, \Closure $resolve): array
    {
        $locations = [$issueLocation];

        foreach ($journey as $step) {
            $location = PsalmBridge::stepLocation($step);

            if ($location instanceof CodeLocation) {
                $locations[] = $location;
            }
        }

        $targets = [];
        $seen = [];

        foreach ($locations as $location) {
            if (isset($seen[$location->file_path])) {
                continue;
            }

            $seen[$location->file_path] = true;
            $target = $resolve($location->file_path);

            if ($target instanceof ShadowTarget) {
                $targets[$location->file_name] = ['path' => $location->file_path, 'target' => $target];
            }
        }

        return $targets;
    }

    /**
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     * @param array<string, array{path: string, target: ShadowTarget}>                     $targets
     *
     * @return list<array{location: ?CodeLocation, label: string, entry_path_type: string}>|null
     *
     * @psalm-mutation-free
     */
    private static function remapSteps(array $journey, array $targets): ?array
    {
        $byPath = [];

        foreach ($targets as $shadow) {
            $byPath[$shadow['path']] = $shadow['target'];
        }

        $remapped = [];

        foreach ($journey as $step) {
            $location = PsalmBridge::stepLocation($step);
            // A step outside any shadow — ordinary application code on the same taint path, or a
            // node with no expression behind it at all — is already reported where the user can
            // find it.
            $target = $location instanceof CodeLocation ? $byPath[$location->file_path] ?? null : null;

            if (!$location instanceof CodeLocation || !$target instanceof ShadowTarget) {
                $remapped[] = $step;

                continue;
            }

            $templateLocation = $target->locationFor(\max(1, $target->templateLineFor($location->getLineNumber())));

            if (!$templateLocation instanceof Raw) {
                return null;
            }

            $remapped[] = PsalmBridge::withStepLocation($step, $templateLocation);
        }

        return $remapped;
    }

    /**
     * @param array<string, array{path: string, target: ShadowTarget}> $targets
     *
     * @return string|null null when a substitution fails outright
     */
    private static function rewriteText(string $journeyText, array $targets): ?string
    {
        foreach ($targets as $fileName => $shadow) {
            $target = $shadow['target'];

            $rewritten = \preg_replace_callback(
                PsalmBridge::locationSummaryPattern($fileName),
                /** @param array<array-key, string> $matches */
                static fn(array $matches): string => PsalmBridge::locationSummary(
                    $target->templateName,
                    \max(1, $target->templateLineFor((int) $matches[1])),
                    (int) $matches[2],
                ),
                $journeyText,
            );

            if ($rewritten === null) {
                return null;
            }

            $journeyText = $rewritten;
        }

        return $journeyText;
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Registry of experimental features that must be explicitly enabled via the
 * `<experimental>` element in psalm.xml (see {@see PluginConfig::isExperimentEnabled()}).
 *
 * Lifecycle (see docs/contributing/README.md):
 *  - introduce: add a case here, default off
 *  - stabilize: remove the case, add its name to RETIRED with a "graduated" notice
 *  - withdraw:  remove the case, add its name to RETIRED with a "withdrawn" notice
 *
 * @internal
 */
enum ExperimentalFeature: string
{
    /** Infer array shapes for Model::toArray()/attributesToArray() (#923, PR #1168). */
    case ModelToArrayShape = 'modelToArrayShape';

    /**
     * Retired feature names → the full notice shown when psalm.xml still requests them.
     * The person retiring a feature writes the exact sentence the user reads, e.g.:
     *  - graduated: "Experimental feature 'x' graduated to stable in v4.16 and no longer needs <experimental>. Remove it from psalm.xml."
     *  - withdrawn: "Experimental feature 'x' was withdrawn (<reason>) and no longer exists. Remove it from psalm.xml."
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const RETIRED = [];

    /**
     * Full notice for a retired (graduated or withdrawn) feature name, or null if the name was
     * never a feature. Requesting a retired name produces this notice, not an error, so upgrading
     * the plugin never turns a previously-valid psalm.xml into a hard failure.
     *
     * @psalm-pure
     */
    public static function retirementNotice(string $name): ?string
    {
        // Bind to a widened local first: Psalm reads the empty `RETIRED` literal as `array<never,
        // never>` on a direct `self::RETIRED[$name]` fetch (EmptyArrayAccess), ignoring the `@var`
        // above. The `@psalm-var` restores the intended element type so the lookup stays valid as
        // the map grows.
        /** @psalm-var array<non-empty-string, non-empty-string> $retired */
        $retired = self::RETIRED;

        return $retired[$name] ?? null;
    }

    /**
     * Lenient resolution of an `<experimental>` element, handling both supported forms:
     *  - `all="true"` → every case minus the recognized `<exclude name="...">` children.
     *  - otherwise (granular mode) → the recognized `<feature name="...">` children, deduped.
     *
     * Never throws and emits no notices, and ignores mode-mismatched or unknown children — for
     * consumers that describe current state (diagnose) rather than validate config
     * (PluginConfig::fromXml() is the strict parser). Must stay in lockstep with
     * PluginConfig::xmlExperimentalFeatures().
     *
     * @return list<self>
     */
    public static function resolveLenient(\SimpleXMLElement $experimental): array
    {
        /** @psalm-var iterable<\SimpleXMLElement> $children */
        $children = $experimental->children();

        if ((string) ($experimental['all'] ?? 'false') === 'true') {
            $excluded = [];

            foreach ($children as $node) {
                if ($node->getName() !== 'exclude') {
                    continue;
                }

                $feature = self::tryFrom((string) ($node['name'] ?? ''));

                if ($feature instanceof self && !\in_array($feature, $excluded, true)) {
                    $excluded[] = $feature;
                }
            }

            return \array_values(\array_filter(
                self::cases(),
                static fn(self $case): bool => !\in_array($case, $excluded, true),
            ));
        }

        $enabled = [];

        foreach ($children as $node) {
            if ($node->getName() !== 'feature') {
                continue;
            }

            $feature = self::tryFrom((string) ($node['name'] ?? ''));

            if ($feature instanceof self && !\in_array($feature, $enabled, true)) {
                $enabled[] = $feature;
            }
        }

        return $enabled;
    }
}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Registry of experimental features that must be explicitly enabled via the
 * `<experimental>` element in psalm.xml (see {@see PluginConfig::isExperimentEnabled()}).
 *
 * Lifecycle (see docs/contributing/README.md):
 *  - introduce: add a case here, default off
 *  - stabilize: remove the case, add its value to GRADUATED
 *  - withdraw:  remove the case, add its value to WITHDRAWN
 *
 * @internal
 */
enum ExperimentalFeature: string
{
    /** Infer array shapes for Model::toArray()/attributesToArray() (#923, PR #1168). */
    case ModelToArrayShape = 'modelToArrayShape';

    /**
     * Feature names that used to be experimental and are now stable/always-on.
     * Requesting one produces a deprecation notice, not an error.
     * Value = plugin version where it graduated.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const GRADUATED = [];

    /**
     * Feature names that were withdrawn without stabilizing.
     * Requesting one produces a deprecation-style notice, not an error.
     * Value = short reason.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const WITHDRAWN = [];

    /**
     * Plugin version $name graduated to stable in, or null if it never graduated.
     *
     * Reads via `static::` rather than `self::` — Psalm only honors the `@var` widening
     * above (past `array{}`, the literal type while both maps are empty) for late-static-bound
     * constant fetches. Enums can't be subclassed, so `static::`/`self::` are identical at
     * runtime; this is purely to keep the lookup typed as the array grows.
     *
     * @psalm-pure
     */
    public static function graduatedIn(string $name): ?string
    {
        return static::GRADUATED[$name] ?? null;
    }

    /**
     * Short reason $name was withdrawn, or null if it never was.
     * See {@see self::graduatedIn()} for the `static::` note.
     *
     * @psalm-pure
     */
    public static function withdrawnBecause(string $name): ?string
    {
        return static::WITHDRAWN[$name] ?? null;
    }
}

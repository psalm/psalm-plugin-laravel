<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Result of analysing a single Blade template.
 *
 * The shape mirrors {@see BladeViewSafety} but is decoupled from the
 * filesystem layer: {@see BladeTemplateScanner::analyze()} returns this object
 * given just a source string, while {@see BladeSafetyMap} adds the view name
 * and resolved file path on top.
 *
 * Three kinds are possible:
 *  - SAFE         -> unsafeKeys === [] && uncertainties === []
 *  - UNSAFE_KEYS  -> unsafeKeys !== [] (uncertainties may be empty)
 *  - UNKNOWN      -> uncertainties !== [] (unsafeKeys may also be non-empty;
 *                     downstream handlers should treat UNKNOWN as the
 *                     dominant signal)
 *
 * UNKNOWN dominates because a finite unsafe-key list is only sound when the
 * scanner saw the whole template. Once any unhandled construct (e.g. an
 * `@extends` directive) appears, additional keys could flow to raw output
 * through paths the v1 scanner does not model, so the conservative answer is
 * "we cannot enumerate keys precisely".
 *
 * The constructor is `private` so the kind-to-payload invariants in the bullet
 * list above are only reachable through {@see safe()}, {@see unsafeKeys()}, and
 * {@see unknown()}. A public constructor would let a caller produce e.g.
 * `(SAFE, ['foo'], [])` and re-introduce the SAFE/UNKNOWN conflation this PR
 * exists to prevent.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class BladeTemplateAnalysis
{
    /**
     * @param list<non-empty-string>       $unsafeKeys    top-level data keys reaching raw output
     * @param list<BladeUncertaintyReason> $uncertainties reasons the scanner could not model the template
     */
    private function __construct(
        public BladeViewSafetyKind $kind,
        public array $unsafeKeys,
        public array $uncertainties,
    ) {}

    /** @psalm-pure */
    public static function safe(): self
    {
        return new self(BladeViewSafetyKind::Safe, [], []);
    }

    /**
     * @param list<non-empty-string> $unsafeKeys
     *
     * @psalm-pure
     */
    public static function unsafeKeys(array $unsafeKeys): self
    {
        if ($unsafeKeys === []) {
            return self::safe();
        }

        return new self(BladeViewSafetyKind::UnsafeKeys, $unsafeKeys, []);
    }

    /**
     * Param type is `list<...>` rather than `non-empty-list<...>` so the
     * runtime emptiness check below remains reachable from Psalm's view
     * (a `non-empty-list` parameter would make `=== []` a TypeDoesNotContainType
     * error). The factory still enforces non-empty at runtime; callers
     * passing `[]` get an `InvalidArgumentException`.
     *
     * @param list<BladeUncertaintyReason> $uncertainties non-empty at runtime; see throw
     * @param list<non-empty-string>       $unsafeKeys    keys observed before the uncertainty was hit
     *
     * @psalm-pure
     */
    public static function unknown(array $uncertainties, array $unsafeKeys = []): self
    {
        // Enforce the documented non-empty contract at runtime: an UNKNOWN
        // with no uncertainty reasons would be indistinguishable from a
        // safe template at the handler layer and re-introduce the
        // SAFE/UNKNOWN conflation this class exists to prevent.
        if ($uncertainties === []) {
            throw new \InvalidArgumentException(
                'BladeTemplateAnalysis::unknown() requires at least one BladeUncertaintyReason.',
            );
        }

        return new self(BladeViewSafetyKind::Unknown, $unsafeKeys, $uncertainties);
    }
}

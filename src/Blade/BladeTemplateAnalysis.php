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
 * `includeEdges` carries the literal `@include('child', [...])` directives
 * the scanner resolved (see {@see BladeIncludeEdge}). The scanner emits one
 * edge per resolvable include AND records {@see BladeUncertaintyReason::IncludeResolved}
 * so the analysis stays UNKNOWN at the source-only layer (an include's
 * contribution to the parent's unsafe keys cannot be computed without
 * inspecting the child template). {@see BladeSafetyMap::build()} runs a
 * fixed-point propagation pass that consumes these edges and flips eligible
 * parents to SAFE or UNSAFE_KEYS.
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
     * @param list<non-empty-string>       $unsafeKeys      top-level data keys reaching raw output
     * @param list<BladeUncertaintyReason> $uncertainties   reasons the scanner could not model the template
     * @param list<BladeIncludeEdge>       $includeEdges    resolvable `@include` edges observed in the template
     * @param list<BladeComponentEdge>     $componentEdges  resolvable `<x-foo :bar="$expr" />` anonymous-component
     *                                                     edges observed in the template; populated alongside a
     *                                                     {@see BladeUncertaintyReason::ComponentResolved} marker
     */
    private function __construct(
        public BladeViewSafetyKind $kind,
        public array $unsafeKeys,
        public array $uncertainties,
        public array $includeEdges = [],
        public array $componentEdges = [],
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
     * @param list<BladeUncertaintyReason> $uncertainties  non-empty at runtime; see throw
     * @param list<non-empty-string>       $unsafeKeys     keys observed before the uncertainty was hit
     * @param list<BladeIncludeEdge>       $includeEdges   resolvable include edges captured during the scan
     * @param list<BladeComponentEdge>     $componentEdges resolvable component edges captured during the scan
     *
     * @psalm-pure
     */
    public static function unknown(
        array $uncertainties,
        array $unsafeKeys = [],
        array $includeEdges = [],
        array $componentEdges = [],
    ): self {
        // Enforce the documented non-empty contract at runtime: an UNKNOWN
        // with no uncertainty reasons would be indistinguishable from a
        // safe template at the handler layer and re-introduce the
        // SAFE/UNKNOWN conflation this class exists to prevent.
        if ($uncertainties === []) {
            throw new \InvalidArgumentException(
                'BladeTemplateAnalysis::unknown() requires at least one BladeUncertaintyReason.',
            );
        }

        return new self(BladeViewSafetyKind::Unknown, $unsafeKeys, $uncertainties, $includeEdges, $componentEdges);
    }

}

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * One literal `@include` directive observed in a Blade template.
 *
 * Models the data flow between a parent template and a child template at a
 * single `@include('child', ['k' => $expr])` call site. {@see BladeSafetyMap}
 * uses these edges to run a fixed-point pass that propagates the child's
 * unsafe-key set onto the parent's safety record, mapping each child key
 * through the parent's explicit data array (and through Laravel's implicit
 * mergeData pass-through; see below).
 *
 * Laravel's `compileInclude()` always appends `array_diff_key(get_defined_vars(),
 * ['__data' => 1, '__path' => 1])` as the trailing argument to the underlying
 * `$__env->make()` call. That argument lands in `Factory::make()`'s
 * `$mergeData` slot, and `Factory::make()` resolves the child's data bag as
 * `array_merge($mergeData, $data)` — explicit `$data` keys win, but any key
 * the parent template has in scope and that the explicit `$data` array does
 * NOT bind is still pushed to the child verbatim.
 *
 * That means propagation has two flows that must both be sound:
 *
 *  - **Explicit binding.** When the parent calls `@include('child', ['x' =>
 *    $y])` and the child has unsafe key `x`, the parent's unsafe-keys gain
 *    every top-level variable found in the expression `$y`. The map field
 *    {@see $explicitKeyMap} holds, for each explicit data-array entry, the
 *    list of top-level variables present in the bound expression.
 *
 *  - **Implicit mergeData pass-through.** When the child has unsafe key
 *    `bio` and the parent's explicit data array does NOT bind `bio`, the
 *    parent's `$bio` (if any) reaches the child via `mergeData`. Soundness
 *    requires we treat `bio` as a parent unsafe-key by its own name. We do
 *    not know whether the parent template actually has `$bio` in scope, but
 *    if no caller of the parent passes a `bio` key the propagation is a
 *    harmless no-op; if a caller does pass `bio`, the propagation surfaces
 *    a real flow that would otherwise be missed.
 *
 * A 2-argument `@include('child')` (no explicit data) is the limit case of
 * "all keys flow through mergeData": {@see $explicitKeyMap} is `null` to
 * distinguish it from "explicit data was an empty array" (which also exists
 * but should be encoded as `[]`, not `null`). In both cases the propagation
 * algorithm reads the same: every child unsafe key K propagates to the
 * parent as K verbatim, except where {@see $explicitKeyMap} binds K.
 *
 * An edge is only created when the include's view-name argument is a literal
 * string AND, in the 3-argument form, the data-array argument is a literal
 * `Array_` node. If either condition fails the scanner emits
 * {@see BladeUncertaintyReason::IncludeDirective} instead, with no edge —
 * propagation cannot proceed without a known target or a known mapping.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class BladeIncludeEdge
{
    /**
     * @param non-empty-string                                $childViewName  literal dotted view name from the
     *                                                                        include directive (`@include('emails.partials.row')`
     *                                                                        becomes `emails.partials.row`)
     * @param array<non-empty-string, list<non-empty-string>>|null $explicitKeyMap key-to-parent-vars binding extracted from
     *                                                                        the literal data array, or null for the
     *                                                                        2-argument `@include('child')` form. An
     *                                                                        empty array means the user wrote
     *                                                                        `@include('child', [])` — explicit binding
     *                                                                        of zero keys, distinct from "no explicit
     *                                                                        binding at all".
     */
    public function __construct(
        public string $childViewName,
        public ?array $explicitKeyMap,
    ) {}
}

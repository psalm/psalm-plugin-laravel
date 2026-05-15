<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Why the scanner gave up on a template (or part of one) and marked it
 * {@see BladeViewSafetyKind::Unknown}.
 *
 * Each reason corresponds to a Blade construct the current scanner cannot model
 * soundly. Reasons are first-class (not free-form strings) so:
 *
 *  - tests can pin the exact reason a fixture produces, instead of asserting on
 *    a comment-style message that drifts;
 *  - downstream handlers can apply different fallback policies per reason
 *    (e.g. treat dynamic includes more conservatively than unparsable PHP);
 *  - metrics counters can answer "which uncertainty dominates" so the next
 *    incremental PR knows where to invest effort.
 *
 * PR 1 emits {@see LAYOUT_SECTION_FLOW}, {@see INCLUDE_DIRECTIVE},
 * {@see COMPONENT_TAG}, {@see FILE_UNREADABLE}, {@see UNPARSABLE_PHP_BLOCK}
 * (compile/parser failure plus unclosed `@php` detection), and
 * {@see UNKNOWN_LOCAL_DEPENDENCY} (raw echo with no extractable top-level
 * variables and a non-literal expression).
 *
 * The remaining cases are reserved for later PRs:
 * {@see UNPARSABLE_PHP_EXPRESSION} for finer-grained malformed-expression
 * reporting, {@see DYNAMIC_VARIABLE_VARIABLE} for the `${$expr}` form that
 * currently rolls up into {@see UNKNOWN_LOCAL_DEPENDENCY}, and
 * {@see DYNAMIC_VIEW_NAME} for view-resolution support at the handler layer.
 *
 * @psalm-api
 */
enum BladeUncertaintyReason
{
    /**
     * Template uses `@extends`, `@section`, `@sectionMissing`, `@yield`,
     * `@show`, `@parent`, `@stack`, `@push`, `@prepend`, `@pushOnce`,
     * `@prependOnce`, `@pushIf`, or `@hasSection`. `compileYield()` and
     * `compileStack()` both emit raw `<?php echo $__env->...; ?>` (no `e()`
     * wrapper), so section/layout data flow is security-relevant and the v1
     * scanner cannot trace it across templates.
     */
    case LayoutSectionFlow;

    /**
     * Template uses an include-family directive whose target view name or data
     * shape the scanner could not resolve at parse time: non-literal `@include`
     * target, `@includeFirst([...])`, `@includeWhen` / `@includeUnless` (which
     * compile to `$__env->renderWhen` / `renderUnless` rather than `make`), or
     * `@each`. The compiled output reaches `$__env->make($view, ...)` /
     * `renderEach(...)` / `first(...)` with arguments the scanner cannot
     * statically inspect. Forces UNKNOWN regardless of any other propagation
     * pass {@see BladeSafetyMap} performs.
     */
    case IncludeDirective;

    /**
     * Template's `@include('child', ['k' => ...])` directives all resolve
     * to literal view names with literal-or-absent data arrays. Emitted by
     * {@see BladeTemplateScanner} alongside a list of {@see BladeIncludeEdge}
     * records so {@see BladeSafetyMap::build()} can run a fixed-point pass
     * that propagates child unsafe keys (mapped through the include's data
     * array and through Laravel's `compileInclude` mergeData pass-through)
     * into the parent template's safety record.
     *
     * Once propagation runs the parent is flipped to SAFE or UNSAFE_KEYS;
     * this case only appears on intermediate {@see BladeTemplateAnalysis}
     * values returned directly by the scanner. Callers that consume a fully
     * built {@see BladeSafetyMap} will never see this case — they see the
     * post-propagation kind/keys instead.
     */
    case IncludeResolved;

    /**
     * Template is a member of an `@include` cycle (template A includes
     * template B includes template A, possibly transitively). The cycle
     * defeats the fixed-point propagation pass: any of the participating
     * templates' unsafe-keys list could in principle depend on its own
     * (yet-to-be-computed) result, so the conservative answer for every
     * cycle member is UNKNOWN. Emitted by {@see BladeSafetyMap::build()}
     * after DFS detects a back-edge.
     *
     * Note: templates that *include into* a cycle member (but are not
     * themselves on the cycle) inherit UNKNOWN through the normal
     * "child is UNKNOWN → parent is UNKNOWN(IncludeDirective)" rule, not
     * through this case. That keeps the {@see IncludeCycle} signal precise:
     * it tags the actual cycle members, not their downstream consumers.
     */
    case IncludeCycle;

    /**
     * Template contains `<x-foo>` / `<x:foo>` component tags, `@component`, or
     * `@slot`. v1 does not resolve component attribute/slot data flow; v2 will
     * add it.
     */
    case ComponentTag;

    /**
     * `view($expr)` or `View::make($expr)` with a non-literal view name at the
     * call site. Surfaced by the handler, not the scanner, but declared here
     * so handlers and scanner share one reason vocabulary.
     */
    case DynamicViewName;

    /**
     * `{!! ... !!}` whose contents could not be parsed as a PHP expression.
     * Detection lands in PR 2 when the regex scanner is replaced with
     * php-parser for echo expressions.
     */
    case UnparsablePhpExpression;

    /**
     * `@php ... @endphp` block with a PHP syntax error. Detection lands in
     * PR 2; PR 1's regex-based PHP pass cannot tell well-formed from
     * malformed PHP.
     */
    case UnparsablePhpBlock;

    /**
     * `{!! $$name !!}` or `{!! ${$expr} !!}` — variable variables defeat
     * static name tracking. Detection lands in PR 2.
     */
    case DynamicVariableVariable;

    /**
     * Raw output of a local whose origin the scanner cannot trace
     * (complex assignment, unsupported directive). Detection lands in PR 2
     * with the local-variable dependency graph.
     */
    case UnknownLocalDependency;

    /**
     * Blade file resolved on disk but `file_get_contents()` returned false
     * (permission, race, etc.). UNKNOWN is the only sound state — silent
     * SAFE would hide the file from the handler.
     */
    case FileUnreadable;
}

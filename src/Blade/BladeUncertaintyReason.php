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
     * Template uses `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`,
     * `@includeFirst`, `@includeIsolated`, or `@each`. Every form compiles to
     * `<?php echo $__env->make($view, ...)->render(); ?>` — raw output of a
     * child template the v1 scanner does not visit. PR 2+ may resolve literal
     * include targets by walking the included template.
     */
    case IncludeDirective;

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

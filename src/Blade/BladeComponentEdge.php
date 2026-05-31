<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * One literal anonymous-component tag observed in a Blade template.
 *
 * Models the data flow between a parent template and an anonymous-component
 * child template at a single `<x-foo :bar="$expr" />` call site. {@see
 * BladeSafetyMap} uses these edges to extend the include-edge fixed-point
 * propagation pass with component flow, mapping each unsafe key the child
 * raw-echoes back to top-level parent variables present in the bound
 * attribute's expression.
 *
 * Anonymous components only. The v1 scanner does not model class components
 * because their render output is produced at runtime by a PHP class method
 * the static analyzer cannot inspect. Any `<x-foo>` whose name does not
 * resolve to an anonymous template file on disk surfaces as
 * {@see BladeUncertaintyReason::ComponentTag} instead of an edge. Namespaced
 * tags (`<x-package::foo>`), `<x-dynamic-component>`, the `@component` /
 * `@slot` directive forms, and any opening tag with a body (slot content)
 * are likewise excluded from v1 and force ComponentTag.
 *
 * Unlike `@include` (see {@see BladeIncludeEdge}), components have NO
 * implicit mergeData pass-through. Laravel's component renderer only binds
 * attributes the parent explicitly wrote; the child's scope does not
 * inherit parent-scope variables that the parent did not pass via an
 * attribute. So a child unsafe key K only contributes to the parent's
 * unsafe keys when K appears in this edge's {@see $explicitKeyMap}; keys
 * not bound are dropped. That divergence is the reason component edges
 * do not reuse {@see BladeIncludeEdge}.
 *
 * View-name resolution is deferred to {@see BladeSafetyMap::build()}.
 * Laravel's `ComponentTagCompiler::guessAnonymousComponentUsingPaths()`
 * probes three candidate view names per anonymous tag (in this order):
 * `components.{name}`, `components.{name}.index`, `components.{name}.{last
 * segment}`. {@see $candidateViewNames} carries the candidates so the map
 * picks the first one that actually exists in the scanned view roots. If
 * no candidate matches, the component is treated as unresolved and the
 * scanner records ComponentTag for this template instead of emitting an
 * edge.
 *
 * Attribute-name mapping mirrors Laravel: kebab-case attributes are
 * camelized (`user-name` → `userName`) because that is the variable name
 * the child template sees. {@see $explicitKeyMap} keys are stored
 * post-camelization so propagation lookups match the child's scanned
 * unsafe keys directly.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class BladeComponentEdge
{
    /**
     * @param non-empty-list<non-empty-string>                $candidateViewNames Laravel anonymous-component candidate view
     *                                                                            names, in probe order. {@see BladeSafetyMap::build()}
     *                                                                            picks the first candidate present in the
     *                                                                            scanned view roots.
     * @param array<non-empty-string, list<non-empty-string>> $explicitKeyMap     attribute-name (camelized) to list of
     *                                                                            top-level parent variables present in the
     *                                                                            bound expression. Static `attr="literal"`
     *                                                                            attributes are present with an empty list
     *                                                                            so propagation knows the key was bound to
     *                                                                            a non-parent value (and must NOT fall
     *                                                                            through to "propagate verbatim"); bound
     *                                                                            `:attr="$expr"` attributes carry the
     *                                                                            extracted parent variables.
     *
     * @psalm-pure
     */
    public function __construct(
        public array $candidateViewNames,
        public array $explicitKeyMap,
    ) {}
}

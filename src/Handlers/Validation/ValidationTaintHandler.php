<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\PropertyFetch;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;

/**
 * Applies taint to validated request data — the *mechanism* side of the
 * feature. The *interpretation* side ("is this expression a validated field
 * read, and which rule governs it?") lives in {@see ValidatedFieldReadResolver};
 * this handler only translates that single answer into Psalm's add/remove
 * taint events. Both directions ask the resolver exactly once, so a keyed
 * accessor (`$req->input('email')`), a `ValidatedInput` accessor
 * (`$req->safe()->input('email')`), a magic property (`$req->email`, #1016),
 * and a tracked inline-validate variable all flow through the same path —
 * there is no longer a parallel branch per syntax.
 *
 * 1. Add taint where the validated value originates user input but the stub's
 *    `@psalm-taint-source` was dropped. A return-type override
 *    ({@see ValidatedTypeHandler}) or a property-type override
 *    ({@see FormRequestPropertyHandler}) makes Psalm skip the stub source, so
 *    we re-introduce it. For `$req->email` there is no stub source on
 *    `Request::__get` at all, and a provider-supplied property type bypasses
 *    `__get` entirely, so the re-source is the only thing tainting the read
 *    (empirically confirmed: a `@psalm-taint-source` on a `__get` stub does
 *    not fire for provider-typed reads).
 *
 * 2. Remove taint per field when the declared validation rule constrains the
 *    value in a way that makes it safe for a specific sink family (e.g. an
 *    'email' rule → safe for 'header' and 'cookie').
 *
 * Design assumption: when a typed FormRequest is injected into a controller,
 * Laravel runs validation before the controller method executes (via
 * ValidatesWhenResolvedTrait). So any keyed accessor read from that
 * FormRequest carries a value that already passed rules() — the rule's taint
 * escape applies even when the caller uses input() instead of validated().
 *
 * Caveat: the escape on the keyed accessors assumes validation has run
 * against the same data pool these accessors read. That assumption can break
 * in a few (rare) scenarios:
 *   - a subclass's passedValidation() calls $this->merge(...) with raw content
 *     on a rule-covered key;
 *   - a subclass overrides validationData() to validate a different source
 *     (e.g. $this->json()->all()) than input() reads;
 *   - input() is called before validation runs (e.g. inside prepareForValidation,
 *     rules(), or authorize()) — the static analyzer cannot see call ordering;
 *   - precognition mode strips rules from the live validator while the static
 *     rules() still parses the full set.
 * In all of these, validated() and safe()->input() still reflect the validated
 * snapshot. Prefer them in security-sensitive paths.
 *
 * NOT handled here (deliberate):
 *   - query(), post(), json(), cookie(), server(), header(), file():
 *     these read from a specific transport rather than the validated merge,
 *     so a rule on 'team_email' does not necessarily describe $req->query('team_email').
 *   - integer/float/boolean/date/enum:
 *     cast methods are not taint sources (see InteractsWithData.phpstub).
 *
 * Upstream workaround for Psalm dropping the stub source on override:
 *   https://github.com/vimeo/psalm/issues/11765
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. {@see ValidatedFieldReadResolver::resolve}
 * rejects non-matching expressions with a leading `instanceof` dispatch before
 * any rule lookup.
 */
final class ValidationTaintHandler implements
    AddTaintsInterface,
    RemoveTaintsInterface,
    AfterFunctionLikeAnalysisInterface,
    AfterFileAnalysisInterface
{
    /**
     * Object IDs of PropertyFetch nodes for which {@see addTaints} already
     * emitted a taint source. Psalm dispatches `AddRemoveTaintsEvent` for the
     * same expression from TWO sites when a property fetch is passed as a
     * function-call argument:
     *
     *   1. `AtomicPropertyFetchAnalyzer::processTaints` — the property-read pass.
     *   2. `ArgumentAnalyzer::processTaintedness` — the argument-binding pass.
     *
     * Both pass the same `PropertyFetch` node as `$event->getExpr()`. Without
     * de-duplication, every `$req->email` reaching a sink ends up with TWO
     * taint sources, producing 2x the expected report count per sink. Method
     * calls do not have this problem because the two sites pass different
     * expressions there (the method-call dispatch uses `$var_expr`, the
     * argument dispatch uses the `MethodCall`).
     *
     * This is mechanism, not parallel logic: the resolver still answers the
     * "is this a validated read?" question once; the dedupe only prevents the
     * resulting source from being emitted twice onto the same graph node.
     *
     * Keyed by `spl_object_id($expr)` and bounded per file/function-like
     * (see {@see afterStatementAnalysis}, {@see afterAnalyzeFile}).
     *
     * @var array<int, true>
     */
    private static array $addTaintsSourcedPropertyFetchIds = [];

    /**
     * Re-source the taint a validated read carries, when the stub source was
     * dropped by a type override (or never existed, as on `Request::__get`).
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        $read = ValidatedFieldReadResolver::resolve($event);

        if ($read === null || $read->sourceTaints === 0) {
            return 0;
        }

        // Property fetches double-dispatch the same node (read pass + argument
        // pass); emit the source only once per node to avoid duplicate sink
        // reports. See {@see $addTaintsSourcedPropertyFetchIds}.
        $expr = $event->getExpr();

        if ($expr instanceof PropertyFetch) {
            $exprId = \spl_object_id($expr);

            if (isset(self::$addTaintsSourcedPropertyFetchIds[$exprId])) {
                return 0;
            }

            self::$addTaintsSourcedPropertyFetchIds[$exprId] = true;
        }

        return $read->sourceTaints;
    }

    /**
     * Remove the taint kinds the field's validation rule guarantees cannot be
     * present in the value.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        return ValidatedFieldReadResolver::resolve($event)?->removedTaints ?? 0;
    }

    /**
     * Drop per-node source markers belonging to the function-like that just
     * finished analysis. Bounds the cache footprint over a long worker
     * lifetime — the cache holds entries only for in-flight functions. We do
     * not stamp a per-function ID on each entry, so the simplest correct
     * strategy is to flush the whole set; subsequent functions re-populate.
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        if ($event->getStatementsSource() instanceof FunctionLikeAnalyzer) {
            self::$addTaintsSourcedPropertyFetchIds = [];
        }

        return null;
    }

    /**
     * Backstop flush at file scope. The function-like flush misses two paths:
     *
     *   - Top-level script expressions (no enclosing function-like) accumulate
     *     markers that the per-function flush never visits.
     *   - PHP recycles `spl_object_id` values once the original object is
     *     garbage-collected. ASTs become GC-eligible per file (Psalm's
     *     `StatementsProvider` does not retain the parsed tree beyond
     *     `FileAnalyzer::analyze`), so a stale marker from file A can collide
     *     with a freshly-allocated PropertyFetch in file B — producing a
     *     silent false negative on the legitimate READ-side fetch.
     *
     * Flushing at file scope bounds the marker set to in-flight AST objects
     * and eliminates the id-reuse race entirely.
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        self::$addTaintsSourcedPropertyFetchIds = [];
    }
}

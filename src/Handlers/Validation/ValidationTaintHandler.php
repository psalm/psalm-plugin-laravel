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
 * Applies taint to validated request data — the *mechanism* side. The
 * interpretation ("is this a validated field read, which rule governs it?")
 * lives in {@see ValidatedFieldReadResolver}; this handler asks it once per
 * direction and translates the answer into Psalm taint events. Every syntax
 * (keyed accessor, `ValidatedInput` accessor, magic property #1016, tracked
 * inline-validate variable) flows through that one path — no per-syntax branch.
 *
 *   1. addTaints re-introduces the source dropped by a type override
 *      ({@see ValidatedTypeHandler} / {@see FormRequestPropertyHandler}). For
 *      `$req->email` no `Request::__get` stub source exists and a provider type
 *      bypasses `__get`, so the re-source is the only thing tainting the read.
 *   2. removeTaints applies the rule's per-field escape (e.g. `email` → safe
 *      for header/cookie).
 *
 * Escape soundness assumes validation ran against the same data pool the
 * accessor reads (true for an injected FormRequest via ValidatesWhenResolvedTrait).
 * It can be unsound when a subclass merges raw content / overrides validationData(),
 * when the read precedes validation (prepareForValidation/rules/authorize), or
 * under precognition. Prefer validated()/safe()->input() in security paths.
 *
 * Not handled (deliberate): transport-specific reads (query/post/json/cookie/
 * server/header/file) — a rule key need not describe them; and cast accessors
 * (integer/float/…) — not taint sources.
 *
 * Upstream: stub source dropped on override — https://github.com/vimeo/psalm/issues/11765.
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 */
final class ValidationTaintHandler implements
    AddTaintsInterface,
    RemoveTaintsInterface,
    AfterFunctionLikeAnalysisInterface,
    AfterFileAnalysisInterface
{
    /**
     * PropertyFetch node IDs already sourced by {@see addTaints}. A fetch passed
     * as a call argument is dispatched twice (property-read pass in
     * `AtomicPropertyFetchAnalyzer` + argument-binding pass in `ArgumentAnalyzer`)
     * with the same node; emitting the source on both doubles the sink reports.
     * Method calls don't hit this (the two sites pass different exprs). Keyed by
     * `spl_object_id`, bounded per file/function-like (see the flush hooks).
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

        if (!$read instanceof \Psalm\LaravelPlugin\Handlers\Validation\ValidatedFieldRead || $read->sourceTaints === 0) {
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
     * Flush the source markers at function-like end to bound the footprint.
     * Entries aren't function-stamped, so flush all; later functions re-populate.
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
     * Backstop flush at file scope. Catches top-level markers the per-function
     * flush never visits, and prevents `spl_object_id` reuse across files (ASTs
     * are GC'd per file) from colliding a stale marker with a fresh PropertyFetch
     * — which would silently drop the source on a legitimate read.
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

<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Removes the `sql` taint from a where-family `$column` argument that is a keyed-MAP —
 * `where(['status_id' => $userValue])` — the false positive #734 / #733.
 *
 * The stubs keep `@psalm-taint-sink sql $column` on the where family: correct for the string form
 * `where($column)` (interpolated as a raw identifier), wrong for the map form, which routes through
 * `Illuminate\Database\Query\Builder::addArrayOfWheres()` → `where($key, '=', $value)` — the KEY is
 * the column, each VALUE is PDO-bound and never interpolated. Only the map form is stripped; the
 * shapes {@see isBoundValueMap} rejects (list literals, `array<string, mixed>`) keep the sink.
 *
 * ## Why call-site scoped
 *
 * The strip happens at the argument node rather than via a stub `@psalm-taint-specialize` (which would
 * silently break `@psalm-flow` of non-SQL taint through the value positions — guarded by
 * TaintedShellWhereValueFlowPreserved.phpt), because {@see AddRemoveTaintsEvent} carries no method id
 * or argument offset, and Psalm dispatches {@see removeTaints} from ~17 analyzers (assignment, return,
 * fetches, …). An UNSCOPED strip of every sealed string-key map (superseded PR #1218) therefore caused
 * false NEGATIVES — a map whose value flowed out via `$map['k']` or a return lost its taint.
 *
 * So {@see beforeExpressionAnalysis} records the first-argument node of each where-family call, and
 * {@see removeTaints} strips only those exact nodes — covering the literal `where(['a' => $v])` and
 * the variable `$conds = [...]; where($conds)` forms, while the same value used elsewhere keeps taint.
 *
 * ## Why the flush is per-FILE, not per-function-like
 *
 * The record→read gap spans a whole call's analysis — receiver first (MethodCallAnalyzer descends
 * `$stmt->var`), then every argument expression, then `processTaintedness`. A closure or arrow-fn in
 * the receiver chain or among the map values completes a FunctionLikeAnalyzer inside that gap, so a
 * per-function-like flush would wipe the record before the argument is read and the #734 FP would
 * return. (Contrast {@see \Psalm\LaravelPlugin\Handlers\Validation\ValidationTaintHandler}, whose dedup
 * set has a record→read gap within a single expression and so flushes per function-like safely.)
 * Flushing at file START ({@see beforeAnalyzeFile}) instead survives a mid-file analysis throw (an
 * end-of-file flush would leave stale ids) and prevents cross-file `spl_object_id` reuse; within one
 * file the AST stays alive, so an id cannot be reused mid-file.
 *
 * Retirement: the method id and argument offset already exist at `ArgumentAnalyzer::processTaintedness()`
 * where the event is built; if an upstream PR adds them to {@see AddRemoveTaintsEvent}, the
 * Before-hook shim retires and {@see removeTaints} gates on the event field. Same auto-retiring
 * pattern as {@see \Psalm\LaravelPlugin\Handlers\Support\ConditionableWhenHandler}. Refs #734, #733,
 * PR #1218.
 */
final class WhereColumnTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /**
     * Where-family methods that carry `@psalm-taint-sink sql $column` AND whose array form Laravel
     * routes through `Builder::addArrayOfWheres()` (verified vs Laravel 13.19: `where` Builder.php:944
     * `is_array` → `addArrayOfWheres`; `orWhere`/`orWhereNot` delegate; `whereNot` wraps a nested
     * `where`; `firstWhere` → `where(...)->first()`).
     *
     * EXCLUDED (their array element is a column, so the sink must stand): `having`/`orHaving` (no
     * `is_array` branch — array column compiles raw); `whereAll`/`whereAny`/`whereNone` and their
     * or-variants `orWhereAll`/`orWhereAny`/`orWhereNone` (all carry `sql $columns` over arrays that
     * are lists of column NAMES, so `whereAll(['col' => $tainted])` correctly keeps flagging);
     * `orderBy` (no keyed-map form).
     *
     * A numeric-string key (e.g. `'1.5'`, which PHP keeps as a string while `is_numeric` is true) is
     * rejected by `isBoundValueMap` like an int key, mirroring `addArrayOfWheres`' own `is_numeric($key)`
     * dispatch, so such maps are never treated as bound-value maps.
     */
    private const WHERE_MAP_METHODS = [
        'where' => true,
        'orwhere' => true,
        'wherenot' => true,
        'orwherenot' => true,
        'firstwhere' => true,
    ];

    /**
     * `spl_object_id` of each recorded where-family first-argument node, consumed by
     * {@see removeTaints}. Flushed per file at {@see beforeAnalyzeFile} (NOT per function-like — the
     * record→read gap spans a closure-bearing call's whole analysis; see the class docblock). A stale
     * cross-file id would wrongly STRIP taint, so the file flush is load-bearing.
     *
     * @var array<int, true>
     */
    private static array $whereColumnArgumentIds = [];

    /**
     * Record the first-argument node id of a where-family call before its arguments are descended
     * into. Never short-circuits.
     */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall && !$expr instanceof StaticCall) {
            return null;
        }

        // Gate on the taint run via the Codebase property (declared `?TaintFlowGraph`, so this is its
        // null-check). Do NOT gate on the statements-analyzer's `data_flow_graph`: taint + unused-var
        // runs wrap THAT in a CombinedFlowGraph, so an instanceof gate there silently disables the fix.
        if (!$event->getCodebase()->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        if (!isset(self::WHERE_MAP_METHODS[\strtolower($expr->name->name)])) {
            return null;
        }

        // getArgs() throws on a first-class callable (`where(...)`).
        if ($expr->isFirstClassCallable()) {
            return null;
        }

        $args = $expr->getArgs();

        if (!isset($args[0]) || $args[0]->unpack) {
            return null;
        }

        // `getArgs()[0]` is written-order first, so a named first arg need not be the `$column` slot.
        // All five allowlisted methods name their first parameter `$column` (verified against the three
        // sink stubs and vendor Laravel), so only record when the first arg is positional or `column:`.
        // A map passed as `value:` (written first) would otherwise be stripped on that VALUE edge — an
        // exotic false negative via `@psalm-flow ($operator, $value) -> return`; missing the FP fix on
        // `where(boolean: 'and', column: $map)` is the safe direction. (Not phpt-covered: the corridor
        // flows through the shared, non-specialized `where` value->return node, so a keep-taint test
        // contaminates the batch, while a specialized `firstWhere` variant has its sql escaped and is
        // vacuous. The guard is by-construction and teeth-checked manually.)
        if ($args[0]->name !== null && $args[0]->name->toLowerString() !== 'column') {
            return null;
        }

        self::$whereColumnArgumentIds[\spl_object_id($args[0]->value)] = true;

        return null;
    }

    /**
     * Remove the `sql` taint when the analysed expression is a where-family first argument (recorded
     * by {@see beforeExpressionAnalysis}) AND its type is the value-binding keyed-map shape.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        // Nested `ArrayItem` dispatches (from ArrayAnalyzer) carry an element, not the argument node.
        if ($expr instanceof \PhpParser\Node\ArrayItem) {
            return 0;
        }

        // The load-bearing scoping check #1218 lacked — before the analyzer instanceof so the empty-set
        // common path is near-free.
        if (!isset(self::$whereColumnArgumentIds[\spl_object_id($expr)])) {
            return 0;
        }

        $statements_source = $event->getStatementsSource();

        if (!$statements_source instanceof StatementsAnalyzer) {
            return 0;
        }

        $type = $statements_source->node_data->getType($expr);

        if (!$type instanceof Union || !self::isBoundValueMap($type)) {
            return 0;
        }

        return TaintKind::INPUT_SQL;
    }

    /**
     * Flush the recorded argument ids at file START. This bounds the footprint, prevents cross-file
     * `spl_object_id` reuse (ASTs are GC'd per file) from colliding a stale record with a fresh node,
     * and — unlike an end-of-file flush — survives a mid-file analysis throw. See the class docblock
     * for why this is per-file rather than per-function-like.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$whereColumnArgumentIds = [];
    }

    /**
     * True only for the keyed-MAP form `['col' => $value]` — a single, SEALED `TKeyedArray` with all
     * string keys. Any numeric key (int or numeric-string, a list / nested-condition literal) makes an
     * element a column, and an unsealed shape can carry dynamic user-controlled keys — either way the
     * sink must stand.
     *
     * @psalm-mutation-free
     */
    private static function isBoundValueMap(Union $type): bool
    {
        if (!$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();

        // Sealed only, via `fallback_params` not the Psalm-7-only `isSealed()` (3.x backport
        // portability): an unsealed keyed array has extra entries with unknown keys, which become columns.
        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return false;
        }

        foreach (\array_keys($atomic->properties) as $key) {
            // Reject int AND numeric-string keys (e.g. '1.5', '01', which PHP keeps as strings). This
            // mirrors addArrayOfWheres' own `is_numeric($key) && is_array($value)` dispatch: a numeric
            // key routes to the nested-column branch where element 0 is a raw column identifier. So the
            // strip does not rely on Psalm's current inability to track depth-2 nested taint; rejecting a
            // numeric-string key with a scalar value is conservative (keeps the sink, the FP-safe
            // direction, and is an absurd shape anyway).
            if (\is_numeric($key)) {
                return false;
            }
        }

        return true;
    }
}

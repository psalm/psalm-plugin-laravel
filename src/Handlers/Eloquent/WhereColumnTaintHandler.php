<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Removes the `sql` taint from the PDO-bound positions of a where-family `$column` ARRAY argument —
 * `where(['status_id' => $userValue])` (#734 / #733) and `where([['name', 'LIKE', $userValue]])`
 * (#1300).
 *
 * The stubs keep `@psalm-taint-sink sql $column` on the where family: correct for the string form
 * `where($column)` (interpolated as a raw identifier), wrong for most of the array form, which routes
 * through `Illuminate\Database\Query\Builder::addArrayOfWheres()`:
 *
 * ```php
 * foreach ($column as $key => $value) {
 *     if (is_numeric($key) && is_array($value)) {
 *         $query->{$method}(...array_values($value), boolean: $boolean);
 *     } else {
 *         $query->{$method}($key, '=', $value, $boolean);
 *     }
 * }
 * ```
 *
 * So only two positions reach SQL as a raw identifier: the array KEY on the `else` branch, and — on
 * the nested branch — `array_values()` ordinal 0 (`$column`). Ordinals 1 and 2 are safe: `$value` is
 * PDO-bound via `addBinding()`, and `$operator` is either matched against Laravel's operator
 * whitelist or demoted to a bound value by `invalidOperator()`. Everything else keeps the sink,
 * including ordinal 3 and beyond — see {@see recordNestedConditionPositions}.
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
 * So {@see beforeExpressionAnalysis} records the nodes of each where-family first argument, and
 * {@see removeTaints} strips only those exact nodes, while the same value used elsewhere keeps taint.
 *
 * ## Two recording modes
 *
 * - **Element-wise** ({@see recordBoundValuePositions}) for an array LITERAL argument: each
 *   `ArrayItem` that {@see addArrayOfWheres} maps onto a bound position is recorded, so a tainted
 *   value dies at its own `arrayvalue-assignment` edge and never reaches the argument node, while a
 *   tainted column position still flows through. This is what makes #1300's nested form precise.
 * - **Whole-argument** ({@see isBoundValueMap}) for everything else (a variable, a call result): a
 *   type-level check that only accepts the sealed all-string-key map, where every element is a value.
 *
 * ### Why a dynamic key records nothing
 *
 * `ArrayAnalyzer` dispatches BOTH the key edge and the value edge of an element with the same
 * `ArrayItem` node, and {@see AddRemoveTaintsEvent} cannot tell them apart. For `[$key => $value]`
 * the key IS the column, so stripping the item to spare its bound value would also strip the genuine
 * identifier sink — `where([$userInput => 1])` must keep flagging. Such items are left alone: their
 * value keeps a false positive it already had, and no true positive is lost.
 *
 * ### Why a numeric-key strip is gated on a scalar value type
 *
 * `is_numeric($key) && is_array($value)` dispatches on the RUNTIME value, so `$row = [$userInput];
 * where([$row])` is the nested form with a raw column at ordinal 0 even though the literal's element
 * is not an array literal. The AST cannot see that, so {@see isScalarValued} requires the element's
 * inferred type to be scalar-or-null before a numeric-key element is stripped.
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
 * pattern as {@see \Psalm\LaravelPlugin\Handlers\Support\ConditionableWhenHandler}. Refs #1300, #734,
 * #733, PR #1218.
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
     * `spl_object_id` of each `ArrayItem` of a where-family array LITERAL that `addArrayOfWheres`
     * maps onto a PDO-bound position. The value tells {@see removeTaints} whether the strip still
     * needs the runtime `is_array($value)` guard: `true` for a numeric-key element (only bound while
     * its value is not an array), `false` for a position that is bound whatever the value holds.
     * Flushed with {@see $whereColumnArgumentIds}.
     *
     * @var array<int, bool>
     */
    private static array $boundValuePositionIds = [];

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

        $argument = $args[0]->value;

        self::$whereColumnArgumentIds[\spl_object_id($argument)] = true;

        if ($argument instanceof Array_) {
            self::recordBoundValuePositions($argument);
        }

        return null;
    }

    /**
     * Remove the `sql` taint when the analysed expression is a bound position of a where-family array
     * literal, or a where-family first argument whose type is the value-binding keyed-map shape (both
     * recorded by {@see beforeExpressionAnalysis}).
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        // `ArrayAnalyzer` dispatches per element with the `ArrayItem`, every other analyzer with the
        // expression itself, so the node class picks the recording mode. Both checks come before the
        // analyzer instanceof so the empty-set common path stays near-free.
        if ($expr instanceof ArrayItem) {
            return self::removeElementTaints($expr, $event);
        }

        // The load-bearing scoping check #1218 lacked.
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
     * `sql` removal for one recorded element of a where-family array literal.
     */
    private static function removeElementTaints(ArrayItem $item, AddRemoveTaintsEvent $event): int
    {
        $requires_scalar_value = self::$boundValuePositionIds[\spl_object_id($item)] ?? null;

        if ($requires_scalar_value === null) {
            return 0;
        }

        if (!$requires_scalar_value) {
            return TaintKind::INPUT_SQL;
        }

        $statements_source = $event->getStatementsSource();

        if (!$statements_source instanceof StatementsAnalyzer) {
            return 0;
        }

        return self::isScalarValued($item, $statements_source) ? TaintKind::INPUT_SQL : 0;
    }

    /**
     * Record the elements of a where-family array literal that `addArrayOfWheres` binds. Everything
     * not recorded keeps the sink, so every uncertain shape simply falls through.
     *
     * @psalm-external-mutation-free
     */
    private static function recordBoundValuePositions(Array_ $literal): void
    {
        foreach (self::positionalItems($literal) ?? [] as $item) {
            $key = $item->key;

            // A dynamic key is the column identifier and shares its `ArrayItem` with the value edge;
            // see the class docblock for why the whole element is then left alone.
            if ($key !== null && !$key instanceof String_ && !$key instanceof Int_ && !$key instanceof Float_) {
                continue;
            }

            // An absent key is the auto-incrementing int one. PHP casts an integer-like string key to
            // int, and `addArrayOfWheres` dispatches on `is_numeric($key)`, which additionally covers
            // '1.5' — a string key PHP keeps as-is.
            $numeric_key = !$key instanceof String_ || \is_numeric($key->value);

            if ($item->value instanceof Array_) {
                if ($numeric_key) {
                    self::recordNestedConditionPositions($item->value);
                }

                // A string key stays on the `where($key, '=', $value)` branch, which binds the array
                // through `flattenValue()`. Degenerate enough to keep the sink rather than model it.
                continue;
            }

            self::$boundValuePositionIds[\spl_object_id($item)] = $numeric_key;
        }
    }

    /**
     * Record the bound positions of a nested condition `[[$column, $operator, $value]]`, which
     * `addArrayOfWheres` forwards as `where(...array_values($value), boolean: $boolean)`.
     *
     * @psalm-external-mutation-free
     */
    private static function recordNestedConditionPositions(Array_ $condition): void
    {
        $items = self::positionalItems($condition) ?? [];

        foreach ($items as $item) {
            // An explicit key can collide with another element's key, which replaces that element in
            // place and leaves the literal one element shorter — `[['name', 0.5 => $x]]` is really
            // `[$x]`, putting a source-ordinal-1 element at `array_values()` ordinal 0, the raw
            // column. Source order is only a reliable parameter position while every key is implicit.
            if ($item->key !== null) {
                return;
            }
        }

        foreach ($items as $ordinal => $item) {
            // `array_values()` drops the keys, so the ordinal alone decides the parameter: 0 is the
            // raw `$column` and keeps the sink. 1 and 2 are bound whatever the element holds, so
            // unlike the flat form they need no `is_array` guard.
            //
            // Ordinal 3 would be `$boolean`, which the grammar concatenates verbatim — but it is
            // unreachable: `addArrayOfWheres` passes `boolean:` by name, so a fourth positional
            // element throws "Named parameter $boolean overwrites previous argument" (verified
            // against laravel/framework v12.14.0, the composer floor, through v13.x). Not stripping
            // it costs nothing and is the right answer if that named argument ever goes away.
            if ($ordinal === 1 || $ordinal === 2) {
                self::$boundValuePositionIds[\spl_object_id($item)] = false;
            }
        }
    }

    /**
     * The literal's elements when every position is knowable, `null` otherwise. A spread contributes
     * an unknown number of elements under unknown keys, and a null element is the hole the parser
     * reports for a destructuring pattern — either way every following position shifts, so the
     * literal carries no reliable positions at all.
     *
     * @return list<ArrayItem>|null
     *
     * @psalm-mutation-free
     */
    private static function positionalItems(Array_ $literal): ?array
    {
        $items = [];

        foreach ($literal->items as $item) {
            if ($item === null || $item->unpack) {
                return null;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * True when the element cannot hold an array at runtime, which is what keeps it on
     * `addArrayOfWheres`' binding branch. Anything wider (`mixed`, a template, an object) keeps the
     * sink.
     */
    private static function isScalarValued(ArrayItem $item, StatementsAnalyzer $statements_source): bool
    {
        $type = $statements_source->node_data->getType($item->value);

        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof Scalar && !$atomic instanceof TNull) {
                return false;
            }
        }

        return true;
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
        self::$boundValuePositionIds = [];
    }

    /**
     * True only for the keyed-MAP form `['col' => $value]` — a single, SEALED `TKeyedArray` with all
     * string keys. Any numeric key (int or numeric-string, a list / nested-condition literal) makes an
     * element a column, and an unsealed shape can carry dynamic user-controlled keys — either way the
     * sink must stand.
     *
     * A literal argument is already covered element-wise by then, so this check only ever decides a
     * non-literal one (`$conds = [...]; where($conds)`), where no per-element node exists to record.
     * Hence the coarse numeric-key rejection stays: without the elements it cannot tell the bound
     * `[1 => $value]` from the raw-column `[1 => [$value]]`.
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
            // Reject int AND numeric-string keys (e.g. '1.5', '01', which PHP keeps as strings),
            // mirroring addArrayOfWheres' own `is_numeric($key)` dispatch. Keeping the sink is the
            // FP-safe direction for the shapes this coarse check cannot separate.
            if (\is_numeric($key)) {
                return false;
            }
        }

        return true;
    }
}

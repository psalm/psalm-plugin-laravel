<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use PhpParser\Node\ArrayItem;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Removes the `sql` taint from a where-family `$column` argument when it is a keyed-MAP literal —
 * `where(['status_id' => $userValue])` — killing the false positive #734 / #733 without weakening the
 * string-form sink.
 *
 * The stubs keep the plain `@psalm-taint-sink sql $column` on the where/having family (see
 * `stubs/common/Database/Query/Builder.phpstub` etc.). That is correct for the string form
 * `where($column)`, which interpolates `$column` as a raw identifier — a real injection vector. But
 * the array form routes through `Builder::addArrayOfWheres()`, which for a string key calls
 * `where($key, '=', $value)`: the literal KEY is the column and each VALUE is PDO-bound. A tainted
 * value in that map is never interpolated, so the blanket sink over-reported (confirmed in pixelfed /
 * monica). This handler subtracts exactly that case.
 *
 * ## Why a map, not any array
 *
 * The gate is a keyed array with ALL STRING keys — the value-binding shape `['col' => $v]`. Two
 * shapes are deliberately NOT stripped, because for them the array elements ARE column identifiers:
 *
 *  - a LIST literal (integer keys) — `whereAll(['a', 'b'])` / `where([[$col, '=', $v]])`. Laravel's
 *    `whereAll`/`whereAny`/`whereNone` iterate the array's VALUES as columns, and
 *    `addArrayOfWheres`' numeric-key branch spreads a nested `[[$col, …]]` so element[0] is a raw
 *    column. Integer keys therefore keep the sink.
 *  - a generic `array<string, mixed>` — `where($request->all())` — whose keys are unknown and can be
 *    user-controlled column names. Not a `TKeyedArray`, so it keeps the sink.
 *
 * ## Why RemoveTaints and not a per-call sink handler
 *
 * A `@psalm-taint-sink` annotation is unconditional — it cannot depend on the argument's type — and a
 * per-call-site sink handler would need either fragile node-id replication or a stub
 * `@psalm-taint-specialize`, which silently breaks `@psalm-flow` propagation of non-SQL taint through
 * the value positions (the documented failure mode in `docs/contributing/taint-analysis.md`). Removing
 * the taint at the argument, keyed off the argument's own type, needs neither: the keyed-string-map
 * shape is unique to the where-family value-binding form, so although {@see removeTaints} is
 * method-agnostic (the event carries no method id), in real code only a where-family map matches —
 * `whereAll`/`insertUsing` take lists, raw sinks take strings. Same mechanism as
 * {@see \Psalm\LaravelPlugin\Handlers\Validation\ValidationTaintHandler}.
 *
 * Maintenance caveat: this strips `sql` from a sealed keyed-string-map argument to ANY call. That is
 * safe only while no `@psalm-taint-sink sql` method takes an associative array whose VALUES are
 * columns (e.g. a future `whereColumn(['a' => $b])` or `select(['alias' => $col])` sink). If such a
 * sink is ever added, gate this strip on the method/receiver so it does not silently defeat it.
 *
 * ## Interim until upstream support
 *
 * This exists only because Psalm cannot express a type-scoped sink. It should retire if a feature like
 * `@psalm-taint-sink sql $column string` ever lands upstream (feature request cross-linked from the
 * PR). Same auto-retiring pattern as
 * {@see \Psalm\LaravelPlugin\Handlers\Support\ConditionableWhenHandler}.
 */
final class WhereColumnTaintHandler implements RemoveTaintsInterface
{
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $statements_source = $event->getStatementsSource();

        // node_data lives on the concrete analyzer. Fires per argument under taint analysis only, so
        // this and the ArrayItem short-circuit keep the common (non-map) path cheap.
        if (!$statements_source instanceof StatementsAnalyzer) {
            return 0;
        }

        $expr = $event->getExpr();

        // Nested `ArrayItem` dispatches carry the element, not the whole argument — key off the
        // argument expression only.
        if ($expr instanceof ArrayItem) {
            return 0;
        }

        $type = $statements_source->node_data->getType($expr);

        if (!$type instanceof Union || !self::isBoundValueMap($type)) {
            return 0;
        }

        return TaintKind::INPUT_SQL;
    }

    /**
     * True only for the pure keyed-MAP form `['col' => $value]` — a single, SEALED `TKeyedArray`
     * whose keys are all strings. Any integer key (a list or nested-condition literal) means an
     * element is a column identifier, and an unsealed shape (a `...<string, mixed>` fallback) can
     * carry dynamic, possibly user-controlled column keys — either way the sink must stand.
     *
     * @psalm-mutation-free
     */
    private static function isBoundValueMap(Union $type): bool
    {
        if (!$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();

        // Sealed only: an unsealed keyed array ($atomic->fallback_params) has additional entries with
        // unknown, possibly user-controlled keys, which become columns.
        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return false;
        }

        foreach (array_keys($atomic->properties) as $key) {
            if (\is_int($key)) {
                return false;
            }
        }

        return true;
    }
}

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * LOAD-BEARING, not a canary: the `sql` sink DOES dispatch through a nullsafe call
 * `$builder?->where([...])` (Psalm narrows the receiver to non-null before the member-call
 * dispatch, so `?->` reaches the same sink a plain `->` would — verified empirically for both the
 * string form and an array-argument sink outside `WHERE_MAP_METHODS`, #1336). This test stays
 * green because the element-wise strip fires, gated by {@see
 * \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::isLaravelBuilder}.
 */
function safeNullsafeArrayWhere(\Illuminate\Http\Request $request, ?\Illuminate\Database\Query\Builder $builder): void {
    $status = (string) $request->input('status');

    $builder?->where(['status_id' => $status]);
}
?>
--EXPECTF--

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Regression pin: `WhereColumnTaintHandler::WHERE_MAP_METHODS` must NOT include `wherecolumn`. That
 * list strips `sql` from the position `addArrayOfWheres()` re-dispatches as a PDO-bound value for
 * `where()`/`orWhere()`/etc. — but `whereColumn`'s array form re-dispatches through `whereColumn`
 * itself, whose grammar never binds anything (see the stub docblock), so both array shapes below
 * must keep flagging. If a future change adds `wherecolumn` to that list, this test goes silent.
 *
 * Runs in the separate `--threads=1` ARGS group: the default taint group already holds four direct
 * (non-array) sources reaching `whereColumn`'s `$first` sink node (this file's siblings plus the
 * Eloquent-chain test), and the nested-array source below is silently swallowed once it joins them —
 * same upstream batch-visitation artifact as `TaintedSqlWhereNestedConditionKeyedElement.phpt`. Both
 * shapes flag reliably in a process that is not saturated. #1303
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnNestedArray(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->whereColumn([[$tainted, '=', 'other']]);
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnKeyedMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->whereColumn([$tainted => 'x']);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL

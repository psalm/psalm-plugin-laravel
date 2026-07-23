--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Regression pin: `WhereColumnTaintHandler::WHERE_MAP_METHODS` must NOT include `wherecolumn`. The
 * two sources below sit exactly on the positions the where()-family element-wise strip (#1302)
 * removes — the nested-condition VALUE ordinal (`[$column, $operator, $value]` ordinal 2) and a
 * static-string-key map's value (`['col' => $value]`) — so if `wherecolumn` were ever added to that
 * list, both would be stripped and this file goes red (the `--EXPECTF--` below expects two
 * `TaintedSql` hits that would no longer fire). `whereColumn`'s own grammar never binds anything (see
 * the stub docblock), so both shapes must keep flagging as-is.
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

    $builder->whereColumn([['safe_col', '=', $tainted]]);
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnKeyedMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->whereColumn(['safe_col' => $tainted]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL

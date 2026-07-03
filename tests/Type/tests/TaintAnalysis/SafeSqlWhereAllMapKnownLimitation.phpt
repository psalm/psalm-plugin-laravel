--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: whereAll(['col' => $tainted]) is NOT flagged.
 *
 * The #734 fix strips the `sql` taint from any keyed-MAP-literal argument — the where-family
 * value-binding shape `['col' => $value]`. whereAll()/whereAny()/whereNone() are normally called with
 * a LIST of column names (`whereAll(['a', 'b'], '=', $v)`), which has integer keys, is NOT stripped,
 * and still flags. Passing a keyed map to whereAll (whose values would become columns) is malformed
 * usage and is the one shape the argument-type strip cannot distinguish from a where() value map.
 * Documented here rather than fixed — the realistic list form is covered by TaintedSqlWhereAll.phpt.
 *
 * @psalm-suppress TooFewArguments
 */
function whereAllKeyedMapNotFlagged(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereAll(['status_id' => $column]);
}
?>
--EXPECTF--

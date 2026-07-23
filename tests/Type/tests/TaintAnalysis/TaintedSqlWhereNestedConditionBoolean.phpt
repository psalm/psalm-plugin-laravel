--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A 4-element nested condition puts its last element at ordinal 3, which maps to $boolean — the one
 * where() parameter the grammar concatenates verbatim rather than binding. The element-wise strip
 * deliberately covers ordinals 1 and 2 only, so this must still flag.
 *
 * The shape cannot actually execute on any supported Laravel: addArrayOfWheres passes `boolean:` by
 * name, so a fourth positional element throws "Named parameter $boolean overwrites previous
 * argument". The test pins the strip's upper bound, not a live injection. Uses whereNot per the
 * batch-artifact guidance (see TaintedSqlWhereWholeArrayInput.phpt). #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeNestedConditionBoolean(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $boolean = (string) $request->input('boolean');

    $builder->whereNot([['name', '=', 'v', $boolean]]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL

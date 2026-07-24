--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Regression guard for the OUTER numeric-key position, which keeps its pre-existing scalar-only
 * gate (unlike the inner nested-condition value ordinal, which now always strips — see
 * SafeSqlWhereNestedConditionMixedValue.phpt): `is_numeric($key) && is_array($value)` dispatches on
 * the RUNTIME value, so an untyped `mixed` here could be an array at runtime (`addArrayOfWheres`'
 * nested branch, where the value becomes the raw column) and must keep the sink. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeOuterMixedValueCouldBeArray(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->where([$request->keyword]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The exact shape reported in #1300: addArrayOfWheres forwards a nested condition as
 * `where(...array_values($value), boolean: $boolean)`. Ordinal 0 is the (literal, untainted) column,
 * ordinal 1 is the operator — matched against Laravel's operator whitelist — and ordinal 2 is
 * PDO-bound, so a tainted value interpolated into the LIKE pattern must not be flagged.
 *
 * Teeth: verified RED before the fix by analysing this shape in a psalm process of its own. In the
 * batched suite it is a CANARY — the upstream artifact noted in TaintedSqlWhereWholeArrayInput.phpt
 * swallows the emission once these files share a process, so it stays green with the fix reverted.
 *
 * @psalm-suppress TooFewArguments
 */
function safeNestedConditionThreeElementForm(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $term = (string) $request->input('term');

    $builder->where([['name', 'LIKE', "%{$term}%"]]);
}

/**
 * The 2-element form `[$column, $value]`: array_values() spreads it as `where($column, $value,
 * boolean: ...)`, so the tainted ordinal-1 element lands in the `$operator` parameter first. Since it
 * is not a recognised operator string, Builder::invalidOperator() demotes it into `$value`, which is
 * then PDO-bound — so this must not be flagged either. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function safeNestedConditionTwoElementForm(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $term = (string) $request->input('term');

    $builder->where([['name', $term]]);
}
?>
--EXPECTF--

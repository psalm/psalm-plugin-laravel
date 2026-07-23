--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A flat LIST element (`whereNot([$column])`, absent key => int 0) takes addArrayOfWheres' `else`
 * branch: `is_numeric($key)` is true but `is_array($value)` is false, so it dispatches
 * `where(0, '=', $value)` — the int key is the column and the element is PDO-bound. The old
 * expectation for this shape dropped the `is_array($value)` conjunct and treated every numeric key
 * as a raw column; #1300 fixes that by gating the numeric-key strip on the element's inferred type
 * being scalar-or-null.
 *
 * @psalm-suppress TooFewArguments
 */
function safeListFormWhereNot(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereNot([$column]);
}
?>
--EXPECTF--

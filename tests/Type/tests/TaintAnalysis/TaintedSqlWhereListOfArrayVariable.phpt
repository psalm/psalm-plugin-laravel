--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * `is_numeric($key) && is_array($value)` dispatches on the RUNTIME value, so a numeric-key element
 * holding an array is the nested-condition branch with a raw column at ordinal 0 — even though the
 * element itself is a variable, not an array literal, so the AST cannot see the nesting directly. The
 * strip is therefore gated on the element's inferred type being scalar-or-null, and an array-typed
 * element must still flag. Uses whereNot per the batch-artifact guidance (see
 * TaintedSqlWhereWholeArrayInput.phpt). #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeListOfArrayVariable(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $row = [$column, '=', 'v'];
    $builder->whereNot([$row]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL

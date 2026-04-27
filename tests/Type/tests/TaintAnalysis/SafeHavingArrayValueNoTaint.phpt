--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Query\Builder also accepts array-form values for having()/orHaving() at
 * runtime. The stub does not model that overload yet, so suppress the argument
 * issue and assert only the taint behaviour here.
 *
 * @psalm-suppress InvalidArgument, MixedAssignment
 */
function safeHavingArrayValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $count = $request->input('count');

    $builder->having(['count' => $count]);
    $builder->orHaving(['count' => $count]);
}
?>
--EXPECTF--

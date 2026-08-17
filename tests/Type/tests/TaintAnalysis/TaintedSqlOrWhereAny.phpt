--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress MixedAssignment, MixedArgument */
function unsafeOrWhereAny(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $columns = $request->input('columns');

    $builder->orWhereAny($columns, 'LIKE', '%test%');
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress MixedAssignment, MixedArgument */
function unsafeWhereAny(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $columns = $request->input('columns');

    $builder->whereAny($columns, 'LIKE', '%test%');
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
TaintedSql on line %d: Detected tainted SQL

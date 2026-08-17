--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeInsertUsing(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $query = $request->input('query');

    $builder->insertUsing(['id', 'name'], $query);
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
MixedAssignment on line %d: Unable to determine the type that $query is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Database\Query\Builder::insertUsing cannot be mixed, expecting Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>|Illuminate\Database\Query\Builder|impure-Closure|string
TaintedSql on line %d: Detected tainted SQL

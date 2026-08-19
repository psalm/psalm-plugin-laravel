--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeFrom(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $table = $request->input('table');

    $builder->from($table);
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
MixedAssignment on line %d: Unable to determine the type that $table is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Query\Builder::from cannot be mixed, expecting Illuminate\Contracts\Database\Query\Expression|Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>|Illuminate\Database\Query\Builder|impure-Closure|string
TaintedSql on line %d: Detected tainted SQL

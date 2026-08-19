--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeFromAlias(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $alias = $request->input('alias');

    $builder->from('users', $alias);
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
MixedAssignment on line %d: Unable to determine the type that $alias is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Database\Query\Builder::from cannot be mixed, expecting null|string
TaintedSql on line %d: Detected tainted SQL

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function filterPosts(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $filter = $request->input('filter');
    $builder->whereRaw($filter);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method filterPosts does not have a return type, expecting void
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
MixedAssignment on line %d: Unable to determine the type that $filter is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Query\Builder::whereRaw cannot be mixed, expecting Illuminate\Contracts\Database\Query\Expression|string
TaintedSql on line %d: Detected tainted SQL

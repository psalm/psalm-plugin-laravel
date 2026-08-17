--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function searchPosts(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $searchTerm = $request->input('search');

    $builder->raw($searchTerm);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method searchPosts does not have a return type, expecting void
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
MixedAssignment on line %d: Unable to determine the type that $searchTerm is being assigned to
TaintedSql on line %d: Detected tainted SQL

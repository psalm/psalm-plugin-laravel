--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function filterPosts(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $builder->whereRaw((string) $request->filter);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method filterPosts does not have a return type, expecting void
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Query\Builder::__construct - expecting connection to be passed
TaintedSql on line %d: Detected tainted SQL

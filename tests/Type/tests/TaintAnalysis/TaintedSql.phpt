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
TaintedSql on line %d: Detected tainted SQL

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
TaintedSql on line %d: Detected tainted SQL

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function listPosts(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $sortClause = $request->input('sort');
    $builder->orderByRaw($sortClause);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

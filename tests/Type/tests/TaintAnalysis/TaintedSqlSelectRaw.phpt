--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function getPostStats(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = $request->input('column');
    $builder->selectRaw($column);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

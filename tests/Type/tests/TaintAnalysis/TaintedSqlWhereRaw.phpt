--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $input = $request->input('filter');
    $builder->whereRaw($input);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

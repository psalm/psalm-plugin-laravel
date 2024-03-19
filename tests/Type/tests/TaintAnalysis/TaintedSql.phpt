--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test_db_raw(\Illuminate\Http\Request $request) {
    $query_builder = new \Illuminate\Database\Query\Builder();
    $user_input = $request->input('foo');

    $query_builder->raw($user_input);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

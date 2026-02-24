--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $conn = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $input = $request->input('id');
    $conn->select("SELECT * FROM users WHERE id = " . $input);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function exportPosts(\Illuminate\Http\Request $request) {
    $conn = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $sql = $request->input('query');
    $conn->unprepared($sql);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL

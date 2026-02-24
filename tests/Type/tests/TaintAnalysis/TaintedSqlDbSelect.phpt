--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showPost(\Illuminate\Http\Request $request) {
    $conn = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $postId = $request->input('id');
    $conn->select("SELECT * FROM posts WHERE id = " . $postId);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL

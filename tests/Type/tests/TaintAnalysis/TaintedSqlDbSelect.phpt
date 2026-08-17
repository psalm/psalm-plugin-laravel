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
MissingReturnType on line %d: Method showPost does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $postId is being assigned to
TaintedSql on line %d: Detected tainted SQL
MixedOperand on line %d: Right operand cannot be mixed

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
MissingReturnType on line %d: Method exportPosts does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $sql is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Connection::unprepared cannot be mixed, expecting string
TaintedSql on line %d: Detected tainted SQL

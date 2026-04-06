--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Connection::escape() uses PDO::quote() internally and is safe for SQL embedding.
 *
 * @psalm-suppress MixedAssignment, MixedArgument
 */
function safeSqlEscape(\Illuminate\Http\Request $request): void {
    $connection = new \Illuminate\Database\Connection(new PDO('sqlite::memory:'));
    $userInput = $request->input('name');

    $escaped = $connection->escape($userInput);

    // Using the escaped value in a raw query should not trigger TaintedSql
    $connection->unprepared("SELECT * FROM users WHERE name = {$escaped}");
}
?>
--EXPECTF--

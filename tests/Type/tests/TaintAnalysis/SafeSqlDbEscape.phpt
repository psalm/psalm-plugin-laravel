--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * DB::escape() uses PDO::quote() internally and is safe for SQL embedding.
 *
 * @psalm-suppress MixedAssignment, MixedArgument
 */
function safeSqlDbEscape(\Illuminate\Http\Request $request): void {
    $userInput = $request->input('name');

    $escaped = \Illuminate\Support\Facades\DB::escape($userInput);

    // Using the escaped value in a raw query should not trigger TaintedSql
    \Illuminate\Support\Facades\DB::unprepared("SELECT * FROM users WHERE name = {$escaped}");
}
?>
--EXPECTF--

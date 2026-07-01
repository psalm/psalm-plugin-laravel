--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runToolSqlLookup(\Laravel\Ai\Tools\Request $request): void {
    // The model picked the value of `q`; treat it like Request::input(): attacker-controllable.
    // `string()` on the stub is annotated `@psalm-taint-source input`, so the
    // Stringable it returns carries taint into the SQL sink below.
    $needle = (string) $request->string('q');

    \Illuminate\Support\Facades\DB::statement('DELETE FROM users WHERE name = ' . $needle);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL

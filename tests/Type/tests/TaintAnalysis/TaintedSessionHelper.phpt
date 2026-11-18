--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function rememberPage(\Illuminate\Http\Request $request): void {
    session(['page' => (string) $request->query('page')]);
}
?>
--EXPECTF--
TaintedSession on line %d: Detected tainted session data
PossiblyInvalidCast on line %d: array<array-key, mixed> cannot be cast to string

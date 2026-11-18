--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function rememberRole(\Illuminate\Http\Request $request): void {
    $request->session()->put('role', (string) $request->input('role'));
}
?>
--EXPECTF--
TaintedSession on line %d: Detected tainted session data

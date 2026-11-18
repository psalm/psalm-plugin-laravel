--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function archive(\Illuminate\Http\Request $request): void {
    system('gzip ' . (string) $request->json('payload'));
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function openLockableFile(\Illuminate\Http\Request $request): void {
    new \Illuminate\Filesystem\LockableFile($request->input('path'), 'r');
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling

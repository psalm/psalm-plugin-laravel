--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function openLockableFile(\Illuminate\Http\Request $request): void {
    new \Illuminate\Filesystem\LockableFile($request->input('path'), 'r');
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of Illuminate\Filesystem\LockableFile::__construct cannot be mixed, expecting string
TaintedFile on line %d: Detected tainted file handling

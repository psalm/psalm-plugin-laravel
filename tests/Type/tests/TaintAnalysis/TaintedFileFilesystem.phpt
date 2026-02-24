--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function downloadAttachment(\Illuminate\Http\Request $request) {
    $filePath = $request->input('file');
    $fs = new \Illuminate\Filesystem\Filesystem();
    $fs->get($filePath);
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeaderByClientExtension(\Illuminate\Http\UploadedFile $file): void {
    response('OK')->header('X-Extension', $file->getClientOriginalExtension());
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

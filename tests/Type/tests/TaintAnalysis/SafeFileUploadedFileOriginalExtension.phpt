--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Symfony UploadedFile::getClientOriginalExtension() returns an extension
 * without path separators or dots, so appending it cannot traverse a path.
 */
function readByClientExtension(\Illuminate\Http\UploadedFile $file): void {
    file_get_contents('uploads/file.' . $file->getClientOriginalExtension());
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request

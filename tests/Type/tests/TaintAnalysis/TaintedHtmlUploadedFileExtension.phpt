--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::getClientOriginalExtension() returns the attacker-controlled extension
 * from the multipart upload — it must be treated as a taint source.
 */
function renderUploadedFileExtension(\Illuminate\Http\UploadedFile $file): void {
    echo $file->getClientOriginalExtension();
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

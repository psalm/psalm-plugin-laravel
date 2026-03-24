--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::get() returns the raw contents of the uploaded file —
 * it must be treated as a taint source.
 */
function renderUploadedFileContents(\Illuminate\Http\UploadedFile $file): void {
    echo $file->get();
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

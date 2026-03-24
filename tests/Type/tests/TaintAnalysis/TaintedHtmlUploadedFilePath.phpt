--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::getClientOriginalPath() returns the attacker-controlled path
 * from webkitdirectory uploads — it must be treated as a taint source.
 */
function renderUploadedFilePath(\Illuminate\Http\UploadedFile $file): void {
    echo $file->getClientOriginalPath();
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

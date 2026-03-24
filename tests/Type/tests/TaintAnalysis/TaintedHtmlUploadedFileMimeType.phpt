--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::getClientMimeType() returns the attacker-controlled Content-Type
 * from the multipart upload — it must be treated as a taint source.
 */
function renderUploadedFileMimeType(\Illuminate\Http\UploadedFile $file): void {
    echo $file->getClientMimeType();
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

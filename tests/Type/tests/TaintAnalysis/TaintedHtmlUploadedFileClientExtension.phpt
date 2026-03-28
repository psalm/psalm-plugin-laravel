--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::clientExtension() guesses the extension from the client-supplied
 * MIME type — it must be treated as a taint source.
 */
function renderUploadedFileClientExtension(\Illuminate\Http\UploadedFile $file): void {
    echo $file->clientExtension();
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * UploadedFile::getContent() (the Symfony File parent API) returns the same
 * raw attacker-controlled bytes as Laravel's get(), so it is a taint source
 * too. Echoing the contents is tainted HTML. Guards the source added for
 * issue #1134 (the contents accessor the file() fix relies on).
 */
function renderUploadGetContent(\Illuminate\Http\UploadedFile $file): void {
    echo $file->getContent();
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

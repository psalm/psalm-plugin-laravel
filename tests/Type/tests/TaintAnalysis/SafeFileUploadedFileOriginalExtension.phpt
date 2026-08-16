--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Symfony UploadedFile::getClientOriginalExtension() returns an extension
 * without path separators or dots, so appending it cannot traverse a path:
 * the `file` sink stays silent while the other source kinds survive.
 *
 * Psalm 6 maps file_get_contents to the `file` sink only (Psalm 7 also maps
 * it to `ssrf`), so the surviving ssrf source is asserted on curl_init, a
 * sink Psalm 6 recognises. Keep both calls: the silent file_get_contents line
 * is the actual regression guard.
 */
function readByClientExtension(\Illuminate\Http\UploadedFile $file): void {
    curl_init('https://example.com/' . $file->getClientOriginalExtension());
    file_get_contents('uploads/file.' . $file->getClientOriginalExtension());
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request

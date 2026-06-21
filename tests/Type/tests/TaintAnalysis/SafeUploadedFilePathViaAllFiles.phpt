--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Same as SafeUploadedFilePathViaRequestFile, for the request's other
 * UploadedFile entry point: Request::allFiles(). It returns the same
 * UploadedFile objects, so reading one via its temp path must not raise
 * TaintedFile / TaintedSSRF (issue #1134). Empty EXPECTF asserts zero output;
 * before the fix this flagged both findings.
 */
function readUploadFromAllFiles(\Illuminate\Http\Request $request): void {
    $file = $request->allFiles()['avatar'] ?? null;
    if ($file instanceof \Illuminate\Http\UploadedFile) {
        file_get_contents((string) $file);
    }
}
?>
--EXPECTF--

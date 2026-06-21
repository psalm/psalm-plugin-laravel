--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Reading an uploaded file via its path is a safe, standard Laravel idiom and
 * must NOT raise TaintedFile / TaintedSSRF (issue #1134).
 *
 * Request::file() returns an UploadedFile object whose only string form is its
 * temp path (PHP-assigned, in upload_tmp_dir): server-controlled, not
 * attacker-controllable. The user controls the file contents and client name
 * (tainted at the UploadedFile accessors), never the path. So file() is no
 * longer an object-level taint source, and coercing the object to its path
 * must reach the file/SSRF sink untainted. The explicit (string) cast is the
 * type-checkable form of what file_get_contents($file) does implicitly.
 *
 * Empty EXPECTF asserts zero Psalm output. Before the fix this flagged
 * TaintedFile + TaintedSSRF (the cast carries the object's source taint), so
 * the empty block is a real regression guard, not a vacuous pass.
 */
function readUpload(\Illuminate\Http\Request $request): void {
    $file = $request->file('avatar');
    if ($file instanceof \Illuminate\Http\UploadedFile) {
        file_get_contents((string) $file);
    }
}
?>
--EXPECTF--

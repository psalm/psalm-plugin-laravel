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
 * longer an object-level taint source, and EVERY way the object coerces to its
 * path (all routing through __toString -> SplFileInfo::getPathname()) must
 * reach the file/SSRF sink untainted.
 *
 * Empty EXPECTF asserts zero Psalm output. Before the fix each line below
 * flagged TaintedFile + TaintedSSRF (the coercions carry the object's source
 * taint), so the empty block is a real regression guard, not a vacuous pass.
 *
 * Concatenation ($file . '') is intentionally omitted: it is the same taint
 * path but Psalm adds an orthogonal ImplicitToStringCast for object-in-concat,
 * which would pollute the zero-output assertion.
 */
function readUpload(\Illuminate\Http\Request $request): void {
    $file = $request->file('avatar');
    if ($file instanceof \Illuminate\Http\UploadedFile) {
        file_get_contents((string) $file);       // explicit cast
        file_get_contents("$file");               // string interpolation
        file_get_contents(strval($file));         // strval()
        file_get_contents(sprintf('%s', $file));  // sprintf %s
    }
}
?>
--EXPECTF--

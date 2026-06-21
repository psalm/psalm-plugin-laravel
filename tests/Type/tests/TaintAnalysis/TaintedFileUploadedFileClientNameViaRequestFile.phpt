--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The #1134 fix rests on a split: an UploadedFile's path coercion is safe, but
 * its user-controlled accessors stay tainted. This locks in the dangerous
 * direction so the fix can never silently become an over-broad escape: a
 * client-supplied filename (getClientOriginalName) is a classic path-traversal
 * vector and MUST still reach a file/SSRF sink, even though file() is no longer
 * an object-level source.
 *
 * Psalm 6 emits only TaintedFile here; Psalm 7 (master) also emits TaintedSSRF
 * for the same file_get_contents sink. The preserved-taint guarantee holds on
 * both — the filename still reaches a sink — so we assert the TaintedFile that
 * both versions agree on.
 */
function readByClientName(\Illuminate\Http\Request $request): void {
    $file = $request->file('avatar');
    if ($file instanceof \Illuminate\Http\UploadedFile) {
        file_get_contents($file->getClientOriginalName());
    }
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling

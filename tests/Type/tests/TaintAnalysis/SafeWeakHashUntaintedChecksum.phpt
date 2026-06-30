--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * md5()/sha1() on non-secret data (file checksums, ETags, cache keys) are
 * legitimate non-cryptographic uses. The taint sinks are gated on the
 * user_secret / system_secret labels, so even input carrying *other* taint
 * kinds (html, sql, ...) must not trigger TaintedUserSecret or
 * TaintedSystemSecret — only secret-class taint should be flagged.
 *
 * Request::input() carries the `html` (and other input-group) taint kind,
 * so flowing it into md5()/sha1() exercises the label gate explicitly.
 */
function etagFromRequestBody(\Illuminate\Http\Request $request): string {
    /** @var string $body */
    $body = $request->input('body');

    return md5($body) . ':' . sha1($body);
}

function checksumFileContents(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === false) {
        return '';
    }

    return md5($contents) . ':' . sha1($contents);
}
?>
--EXPECTF--

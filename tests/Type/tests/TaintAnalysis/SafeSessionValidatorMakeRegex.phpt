--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A value Validator::make() checked against a `regex:` rule of its key can be kept in the session once the
 * validator doesn't fail.
 */
function rememberAgent(\Illuminate\Http\Request $request): void {
    $agent = trim((string) $request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent],
        ['agent' => ['required', 'regex:#^[a-zA-Z0-9 _.\-]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    session(['agent' => $agent]);
}
?>
--EXPECTF--

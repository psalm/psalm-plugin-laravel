--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A rule of another key, or none restricting the characters, doesn't validate the value for the session.
 */
function rememberAgent(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent],
        ['agent' => 'required|string', 'name' => ['regex:#^[a-z]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    session(['agent' => $agent]);
}
?>
--EXPECTF--
TaintedSession on line %d: Detected tainted session data

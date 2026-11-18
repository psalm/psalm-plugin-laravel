--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A field the validator may exclude, or a rule of a nested attribute, doesn't validate the value under the key.
 */
function rememberExcluded(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent, 'guest' => '1'],
        ['agent' => ['exclude_if:guest,1', 'regex:#^[a-z]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    session(['agent' => $agent]);
}

function rememberNested(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent],
        ['agent.name' => ['regex:#^[a-z]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    session(['agent' => $agent]);
}
?>
--EXPECTF--
TaintedSession on line %d: Detected tainted session data
TaintedSession on line %d: Detected tainted session data

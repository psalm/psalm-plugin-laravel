--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A value Validator::make() (or validator()) checked against a `regex:` rule of its key can be echoed once
 * the validator doesn't fail.
 */
function showAgent(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent],
        ['agent' => ['required', 'regex:#^[a-zA-Z0-9 _.\-]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    echo $agent;
}

function showAgentWithHelper(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = validator(['agent' => $agent], ['agent' => 'required|regex:#^[a-z]+$#']);
    if (!$validator->fails()) {
        echo $agent;
    }
}
?>
--EXPECTF--

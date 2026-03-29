--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedForgetPath(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $path */
    $path = $request->input('path');
    $jar->forget('safe_name', $path);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

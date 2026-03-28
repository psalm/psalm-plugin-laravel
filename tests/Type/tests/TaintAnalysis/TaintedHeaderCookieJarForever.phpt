--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedForeverCookie(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $value */
    $value = $request->input('cookie_value');
    $jar->forever('preference', $value);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

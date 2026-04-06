--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedForgetDomain(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $domain */
    $domain = $request->input('domain');
    $jar->forget('safe_name', '/', $domain);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedCookieDomain(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $domain */
    $domain = $request->input('cookie_domain');
    $jar->make('session_id', 'abc123', 0, '/', $domain);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

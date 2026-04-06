--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedCookiePath(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $path */
    $path = $request->input('cookie_path');
    $jar->make('session_id', 'abc123', 0, $path);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

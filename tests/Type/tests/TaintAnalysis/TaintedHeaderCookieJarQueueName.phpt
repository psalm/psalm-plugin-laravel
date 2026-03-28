--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedQueueCookieName(\Illuminate\Http\Request $request, \Illuminate\Cookie\CookieJar $jar): void {
    /** @var string $name */
    $name = $request->input('cookie_name');
    $jar->queue($name, 'safe-value');
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

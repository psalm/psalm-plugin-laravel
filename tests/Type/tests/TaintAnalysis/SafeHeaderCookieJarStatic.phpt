--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function safeCookieJar(\Illuminate\Cookie\CookieJar $jar): void {
    $jar->make('session_id', 'abc123');
    $jar->forever('preference', 'dark_mode');
    $jar->queue('tracking', 'opt_out');
    $jar->expire('old_cookie');
    $jar->forget('old_cookie');
    cookie('session_id', 'abc123');
}
?>
--EXPECTF--

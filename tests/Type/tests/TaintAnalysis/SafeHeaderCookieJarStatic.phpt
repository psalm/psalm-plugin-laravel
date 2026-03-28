--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function safeCookieJar(\Illuminate\Cookie\CookieJar $jar): void {
    $jar->make('session_id', 'abc123');
    $jar->forever('preference', 'dark_mode');
    $jar->forget('old_cookie');
}
?>
--EXPECTF--

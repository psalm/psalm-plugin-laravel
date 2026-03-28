--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function taintedCookieHelper(\Illuminate\Http\Request $request): void {
    /** @var string $value */
    $value = $request->input('cookie_value');
    cookie('session_id', $value);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header

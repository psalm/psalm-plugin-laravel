--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Callback dispatcher — user controls the URL
 * that receives the HTTP callback.
 */
function dispatchCallback(\Illuminate\Http\Request $request): void {
    $callbackUrl = (string) $request->input('callback_url');
    $http = new \Illuminate\Http\Client\PendingRequest();
    $http->send('POST', $callbackUrl);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request

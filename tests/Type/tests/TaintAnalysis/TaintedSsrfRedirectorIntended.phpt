--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * OAuth login flow — user controls the fallback redirect URL
 * via a ?redirect_to= query parameter.
 */
function loginRedirect(\Illuminate\Http\Request $request, \Illuminate\Routing\Redirector $redirector): void {
    $fallback = (string) $request->input('redirect_to');
    $redirector->intended($fallback);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header
%ATaintedSSRF on line %d: Detected tainted network request

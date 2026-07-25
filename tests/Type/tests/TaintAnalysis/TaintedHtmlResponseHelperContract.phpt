--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Zero-argument `response()` is typed as the `Illuminate\Contracts\Routing\ResponseFactory`
 * contract, not the concrete class. Psalm does not propagate taint sinks from an
 * implementation to the interface, so this flow is only visible because the sinks are
 * mirrored onto the contract stub (Contracts/Routing/ResponseFactory.phpstub).
 *
 * `json()` deliberately does NOT sink through this path; its clean counterpart is pinned
 * in SafeResponseFactoryJsonNoHtmlTaint.phpt (a negative cannot be asserted here, since
 * the `%A` segments below are a lower bound).
 */

function responseHelperMakeIsTainted(Request $request): void
{
    response()->make($request->input('body'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

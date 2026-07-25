--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Zero-argument `response()` is typed as the `Illuminate\Contracts\Routing\ResponseFactory`
 * contract, not the concrete class. Psalm does not propagate taint sinks from an
 * implementation to the interface, so these flows are only visible because the sinks are
 * mirrored onto the contract stub (Contracts/Routing/ResponseFactory.phpstub).
 */

function responseHelperMakeIsTainted(Request $request): void
{
    response()->make($request->input('body'));
}

function responseHelperJsonIsTainted(Request $request): void
{
    response()->json($request->input('data'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedHtml on line %d: Detected tainted HTML

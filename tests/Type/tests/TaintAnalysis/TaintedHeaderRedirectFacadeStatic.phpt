--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * `Redirect::to()` forwards to `Illuminate\Routing\Redirector::to()`, whose `$path`
 * is a header sink (open redirect). The `redirect()` helper form is covered by
 * TaintedHeaderRedirect.phpt; this pins the facade static form.
 */

function facadeStaticToIsTainted(Request $request): void
{
    Redirect::to((string) $request->input('return_url'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * `Response::make()` forwards to `Illuminate\Routing\ResponseFactory::make()`, whose
 * `$content` is an html sink. The concrete-receiver form is covered by
 * TaintedHtmlResponseFactory.phpt; this pins the facade static form.
 */

function facadeStaticMakeIsTainted(Request $request): void
{
    Response::make($request->input('body'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

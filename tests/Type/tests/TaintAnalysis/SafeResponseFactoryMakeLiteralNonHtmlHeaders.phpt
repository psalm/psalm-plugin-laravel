--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;

/**
 * A literal attachment disposition makes the browser download the response rather than render
 * it as HTML. The exception is pinned through every receiver route carrying the default sink.
 */
function makeAttachmentResponses(Request $request, ResponseFactory $concrete, ResponseFactoryContract $contract): void
{
    $concrete->make($request->input('concrete-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
    $contract->make((string) $request->input('contract-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
    Response::make($request->input('facade-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
}

function makeCaseInsensitiveAttachment(ResponseFactory $response, Request $request): void
{
    $response->make($request->input('case-insensitive-download'), 200, ['Content-Disposition' => " \tATTACHMENT ; filename=members.csv\t "]);
}

/**
 * The reported shape in #1345. `response()` resolves to the contract, so this is the route the
 * receiver check has to accept for the issue to be fixed at all.
 */
function makeAttachmentThroughHelper(Request $request): void
{
    response()->make((string) $request->input('helper-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
}
?>
--EXPECTF--

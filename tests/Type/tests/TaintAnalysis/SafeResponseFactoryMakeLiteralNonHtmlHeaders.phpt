--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;

interface ResponseFactoryMarker
{
}

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

function makeBareAttachment(ResponseFactory $response, Request $request): void
{
    $response->make($request->input('bare-attachment-download'), 200, ['Content-Disposition' => 'attachment']);
}

/**
 * The verbatim two-header shape from the #1345 report: an explicit content type next to the
 * attachment disposition.
 */
function makeCsvExportFromIssueReport(Request $request): void
{
    response()->make((string) $request->input('issue-shape-download'), 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="export.csv"',
    ]);
}

/**
 * The reported shape in #1345. `response()` resolves to the contract, so this is the route the
 * receiver check has to accept for the issue to be fixed at all.
 */
function makeAttachmentThroughHelper(Request $request): void
{
    response()->make((string) $request->input('helper-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
}

/**
 * Content produced directly by a function call. `decrypt()` carries `@psalm-flow` without
 * `@psalm-taint-specialize`, so its argument-to-return edge is shared project wide. Suppressing at
 * emission time writes nothing to the graph, so this call is exempt without touching that edge.
 */
function makeAttachmentFromCallContent(Request $request): void
{
    Response::make(decrypt((string) $request->input('call-content-download')), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
}

/**
 * Both intersection orders. The AST side no longer inspects the receiver at all: the journey label
 * proves Psalm resolved the call to the factory's own stubbed sink, which is stronger evidence than
 * a call-site type check and is not confused by which atomic the intersection made primary. Both
 * therefore fall under the conforming-receiver trust stated in `docs/security.md`; the previous
 * `extra_types` guard declined them.
 */
function makeWithIntersectedReceiver(Request $request, ResponseFactoryMarker&ResponseFactory $response): void
{
    $response->make($request->input('intersected'), 200, ['Content-Disposition' => 'attachment']);
}

function makeWithFactoryPrimaryIntersection(Request $request, ResponseFactory&ResponseFactoryMarker $response): void
{
    $response->make($request->input('factory-primary-intersected'), 200, ['Content-Disposition' => 'attachment']);
}
?>
--EXPECTF--

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-file-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

final class CustomResponseFactory
{
    public function make(string $content, int $status, array $headers): void
    {
        echo $content;
    }
}

/**
 * Only a direct, literal attachment disposition on a call Psalm resolved to the factory's own sink
 * is exempt. Default, content-type-only, dynamic, list-valued, malformed, non-attachment,
 * duplicate, underscore, CRLF-smuggling, and computed-key disposition headers, named-argument
 * calls, and custom receivers retain the default HTML sink.
 *
 * The unique ARGS line runs this file in its own batch. So many flows converging on the shared
 * `make#1` node cross Psalm's visited-node pruning in a shared batch, which silently drops the
 * last findings; standalone, all of them report.
 *
 * The headers argument must be an array literal AT the call. A variable holding the very same
 * literal is not followed, so it keeps the sink: the exemption is deliberately syntactic.
 */
function makeResponsesWithUnprovenHeaders(Request $request, ResponseFactory $response, CustomResponseFactory $custom): void
{
    $disposition = 'attachment';
    $attachmentHeaders = ['Content-Disposition' => 'attachment; filename="members.csv"'];

    $response->make($request->input('default'));
    $response->make($request->input('variable-headers'), 200, $attachmentHeaders);
    $response->make($request->input('csv'), 200, ['Content-Type' => 'text/csv']);
    $response->make($request->input('dynamic-disposition'), 200, ['Content-Disposition' => $disposition]);
    $response->make($request->input('list-disposition'), 200, ['Content-Disposition' => ['attachment']]);
    $response->make($request->input('attachment-suffix'), 200, ['Content-Disposition' => 'attachmentx']);
    $response->make($request->input('malformed-disposition'), 200, ['Content-Disposition' => 'attachment filename=members.csv']);
    $response->make($request->input('empty-parameter'), 200, ['Content-Disposition' => 'attachment;']);
    $response->make($request->input('whitespace-parameter'), 200, ['Content-Disposition' => "attachment; \t"]);
    $response->make($request->input('inline-disposition'), 200, ['Content-Disposition' => 'inline; filename="members.csv"']);
    $response->make($request->input('underscore-disposition'), 200, ['Content_Disposition' => 'attachment']);
    $response->make($request->input('duplicate-disposition'), 200, [
        'Content-Disposition' => 'attachment',
        'content-disposition' => 'attachment',
    ]);
    $response->make($request->input('dynamic-key'), 200, ['Content-' . 'Disposition' => 'attachment']);
    $response->make($request->input('crlf-disposition'), 200, ['Content-Disposition' => "attachment;\nX-Injected: x"]);
    $response->make($request->input('trailing-newline-disposition'), 200, ['Content-Disposition' => "attachment\n"]);
    $response->make(content: $request->input('named-arguments'), status: 200, headers: ['Content-Disposition' => 'attachment']);
    $custom->make((string) $request->input('custom'), 200, ['Content-Disposition' => 'attachment']);
}

/**
 * The exempt journey labels name the concrete factory, its contract, and the fully qualified
 * facade. The root `\Response` alias reaches the same sink under the unqualified label
 * `Response::make`, which is left out of that list, so it keeps the sink. Accepted imprecision: a
 * project that trims the alias registry is unaffected either way, and the miss is a retained
 * finding rather than a dropped one.
 */
function makeThroughRootAliasFacade(Request $request): void
{
    \Response::make($request->input('root-alias-download'), 200, ['Content-Disposition' => 'attachment; filename="members.csv"']);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML

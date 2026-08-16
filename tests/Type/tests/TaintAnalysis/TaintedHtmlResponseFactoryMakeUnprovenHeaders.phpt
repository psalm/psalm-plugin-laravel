--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
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

interface ResponseFactoryMarker
{
}

final class IntersectedResponseFactory extends ResponseFactory implements ResponseFactoryMarker
{
    #[\Override]
    public function make($content = '', $status = 200, array $headers = [])
    {
        return new \Illuminate\Http\Response($content, $status, $headers);
    }
}

/**
 * Only a direct, literal attachment disposition on an exact factory is exempt. Default,
 * content-type-only, dynamic or list-valued dispositions, custom receivers, and intersections
 * retain the default HTML sink.
 */
function makeResponsesWithUnprovenHeaders(Request $request, ResponseFactory $response, CustomResponseFactory $custom): void
{
    $disposition = 'attachment';

    $response->make($request->input('default'));
    $response->make($request->input('csv'), 200, ['Content-Type' => 'text/csv']);
    $response->make($request->input('dynamic-disposition'), 200, ['Content-Disposition' => $disposition]);
    $response->make($request->input('list-disposition'), 200, ['Content-Disposition' => ['attachment']]);
    $custom->make((string) $request->input('custom'), 200, ['Content-Disposition' => 'attachment']);
}

function makeWithIntersectedReceiver(Request $request, ResponseFactory&ResponseFactoryMarker $response): void
{
    $response->make($request->input('intersected'), 200, ['Content-Disposition' => 'attachment']);
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

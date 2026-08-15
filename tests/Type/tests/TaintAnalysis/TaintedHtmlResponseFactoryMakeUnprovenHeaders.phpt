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

/**
 * The literal-header exception is deliberately narrow. Every path below remains an HTML sink:
 * default/dynamic headers, explicit HTML, active SVG and an unrecognised MIME type, an overridden
 * safe header, ambiguous or dynamic string lists, a widened receiver, and a userland method whose
 * name happens to be `make`.
 */
function makeResponsesWithUnprovenHeaders(Request $request, ResponseFactory $response, CustomResponseFactory $custom): void
{
    $headers = ['Content-Type' => 'text/csv'];
    $type = 'text/csv';

    $response->make($request->input('default'));
    $response->make($request->input('dynamic'), 200, $headers);
    $response->make($request->input('html'), 200, ['Content-Type' => 'text/html']);
    $response->make($request->input('svg'), 200, ['Content-Type' => 'image/svg+xml']);
    $response->make($request->input('unsupported'), 200, ['Content-Type' => 'application/x-custom-export']);
    $response->make($request->input('overridden'), 200, ['Content-Type' => 'text/csv', 'content-type' => 'text/html']);
    $response->make($request->input('multiple-types'), 200, ['Content-Type' => ['text/csv', 'text/html']]);
    $response->make($request->input('multiple-dispositions'), 200, ['Content-Disposition' => ['attachment', 'inline']]);
    $response->make($request->input('dynamic-list'), 200, ['Content-Type' => [$type]]);
    $custom->make((string) $request->input('custom'), 200, ['Content-Type' => 'text/csv']);
}

function makeWithWidenedReceiver(Request $request, ResponseFactory|CustomResponseFactory $response): void
{
    $response->make((string) $request->input('widened'), 200, ['Content-Type' => 'text/csv']);
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

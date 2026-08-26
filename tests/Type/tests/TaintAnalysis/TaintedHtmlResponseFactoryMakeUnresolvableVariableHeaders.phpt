--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-file-cache --no-reflection-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * #1416 widening 1 keeps the sink whenever the const-fold proof cannot rule out a second write.
 * The unique ARGS line runs this file in its own batch.
 */
function makeWithReassignedHeaders(Request $request, ResponseFactory $response, bool $download): void
{
    $output = (string) $request->input('csv');
    $headers = ['Content-Disposition' => 'inline'];

    if ($download) {
        $headers = ['Content-Disposition' => 'attachment; filename="members.csv"'];
    }

    $response->make($output, 200, $headers);
}

function makeWithHeadersPassedElsewhere(Request $request, ResponseFactory $response): void
{
    $output = (string) $request->input('csv2');
    $headers = ['Content-Disposition' => 'attachment; filename="members.csv"'];

    \error_log(\print_r($headers, true));

    $response->make($output, 200, $headers);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-reflection-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * #1416 widening 1. The headers array is a literal one statement up, held in a variable that is
 * assigned exactly once and never mutated before the call. The unique ARGS line runs this file in
 * its own batch: every `make()` call in the project meets at the same shared sink node, and a
 * shared batch would silently drop this file's finding if the exemption failed to apply.
 */
function makeCsvExportFromReportedShape(Request $request, ResponseFactory $response): void
{
    $output = (string) $request->input('csv');
    $headers = [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="members.csv"',
    ];

    $response->make($output, 200, $headers);
}
?>
--EXPECTF--

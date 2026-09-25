--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * A literal attachment disposition (plus a literal, safe content type) exempts the TaintedHtml
 * content sink (#1345/#1417), but that exemption handler gates on `instanceof TaintedHtml` only
 * and must never suppress TaintedHeader on a genuinely tainted header VALUE in the same literal
 * array. The content is ALSO tainted here so the exemption actually runs and suppresses the html
 * finding: only then does this prove the header sink survives it rather than merely proving the
 * header sink fires when the html sink was never in play.
 */
function makeExportWithTaintedCustomHeader(Request $request, ResponseFactory $response): void
{
    $body = (string) $request->input('body');
    $team = (string) $request->input('team');

    $response->make($body, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="export.csv"',
        'X-Team' => $team,
    ]);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

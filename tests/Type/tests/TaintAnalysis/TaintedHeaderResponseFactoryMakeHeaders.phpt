--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * #1418. `ResponseFactory::make()`'s $headers argument reaches the response verbatim and is never
 * escaped, so a tainted header value is a header-injection sink on its own, independent of the
 * existing html sink on $content. Routed through the `response()` helper, which resolves to the
 * contract (see Foundation/helpers.phpstub). $csv stays a clean parameter so only the header sink
 * fires.
 */
function makeExportWithTaintedHeaderFilename(Request $request, string $csv): void
{
    $team = (string) $request->input('team');

    response()->make($csv, 200, ['Content-Disposition' => "attachment; filename=\"{$team}.csv\""]);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

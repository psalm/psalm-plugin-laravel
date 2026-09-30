--CONFLICTS--
psalm-1418-TaintedHeaderResponseHelperHeaders
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * #1418. The `response()` helper forwards straight to `ResponseFactory::make()` (3-arg direct
 * form, not `response()->make()`), so its own $headers argument needs the same header sink as
 * the factory it wraps. $csv stays a clean parameter so only the header sink fires. The unique
 * CONFLICTS key runs this file in its own batch: co-batched calls into this sink family meet at
 * one shared sink node and later-arriving flows are pruned.
 */
function makeExportWithTaintedHeaderFilename(Request $request, string $csv): void
{
    $team = (string) $request->input('team');

    response($csv, 200, ['Content-Disposition' => "attachment; filename=\"{$team}.csv\""]);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

--CONFLICTS--
psalm-1418-TaintedHeaderResponseConstructorHeaders
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * #1418. `Illuminate\Http\Response::__construct`'s $headers argument shares the same sink family as
 * `ResponseFactory::make()`. $csv stays a clean parameter so only the header sink fires. The
 * unique CONFLICTS key runs this file in its own batch: co-batched calls into this sink family
 * meet at one shared sink node and later-arriving flows are pruned.
 */
function makeResponseWithTaintedHeaderFilename(Request $request, string $csv): void
{
    $team = (string) $request->input('team');

    $response = new \Illuminate\Http\Response($csv, 200, ['Content-Disposition' => "attachment; filename=\"{$team}.csv\""]);

    echo $response;
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header

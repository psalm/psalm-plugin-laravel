--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * #1418. `Illuminate\Http\Response::__construct`'s $headers argument shares the same sink family as
 * `ResponseFactory::make()`. $csv stays a clean parameter so only the header sink fires.
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

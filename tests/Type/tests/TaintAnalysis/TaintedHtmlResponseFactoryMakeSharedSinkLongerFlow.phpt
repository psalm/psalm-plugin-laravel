--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-cache --no-file-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * Every `make()` call in the project meets at the same taint graph node,
 * `Illuminate\Routing\ResponseFactory::make#1`. The shorter flow below is exempt (attachment
 * download), the longer one is not: the exemption must drop only its own finding, and the longer
 * flow arriving at the shared node later must still report.
 *
 * The unique ARGS line runs this file in its own batch, so no other fixture's `make()` flow shares
 * the node.
 */
function shallowExemptDownload(Request $request, ResponseFactory $response): void
{
    $response->make((string) $request->input('shallow'), 200, ['Content-Disposition' => 'attachment']);
}

/** The dangerous end of the long chain: no headers at all, so the response renders as HTML. */
function deepSink(string $value, ResponseFactory $response): void
{
    $response->make($value, 200, []);
}

function deepHopThree(string $value, ResponseFactory $response): void
{
    deepSink($value, $response);
}

function deepHopTwo(string $value, ResponseFactory $response): void
{
    deepHopThree($value, $response);
}

function deepHopOne(string $value, ResponseFactory $response): void
{
    deepHopTwo($value, $response);
}

function deepDangerousEntry(Request $request, ResponseFactory $response): void
{
    deepHopOne((string) $request->input('deep'), $response);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML

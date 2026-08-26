--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-cache --no-file-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * ACCEPTED LIMITATION. Every `make()` call in the project meets at the same taint graph node,
 * `Illuminate\Routing\ResponseFactory::make#1`, and `TaintFlowGraph::getChildNodes()` walks that
 * node once: its `visited_source_ids` set discards every LONGER flow arriving after the first. The
 * deep flow below is therefore already unreported on master, without any exemption in play.
 *
 * What the exemption adds is that the surviving SHORT flow is now dropped as well, so the sink
 * reports nothing at all rather than reporting one of its two flows. Order is what pins this: the
 * exempt call has to be the shorter path. With the deep call exempt instead, the shallow one still
 * reports.
 *
 * Upstream, same family as vimeo/psalm#11924. If Psalm stops pruning by source id, this test goes
 * red with a `TaintedHtml` for `deepSink()` and should then be promoted to a regular expectation.
 * The caveat this pins lives on the `ResponseFactoryTaintHandler` class docblock.
 *
 * The unique ARGS line runs this file in its own batch: sharing the `make#1` node with any other
 * fixture makes which flow arrives first depend on the batch, not on this file.
 *
 * Not vacuous, proven by two mutations that each turn it red. Delete `shallowExemptDownload()` and
 * `deepSink()` reports, so the deep chain is a real reachable flow rather than dead fixture code.
 * Give the shallow call non-attachment headers and it reports instead while the deep one stays
 * silent, which is master's behaviour and shows the pruning is not caused by the exemption.
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

/**
 * Not part of the limitation. It reaches a different sink, so it cannot be pruned by the `make#1`
 * walk, and its two findings are what keep the expectation below from passing vacuously: a fixture
 * that stopped analysing at all would drop these too.
 */
function echoUnrelatedControl(Request $request): void
{
    echo (string) $request->input('control');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

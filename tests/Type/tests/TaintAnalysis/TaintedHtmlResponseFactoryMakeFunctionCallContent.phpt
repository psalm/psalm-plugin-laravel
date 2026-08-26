--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * A `@psalm-flow` callee without `@psalm-taint-specialize`, mirroring `decrypt()`. The body
 * returns a constant so the only taint route is the flow edge Psalm adds per call site — the
 * edge a leaked html removal would poison. The unique ARGS line above runs this file in its own
 * single-file batch: a shared batch lets a sibling file rewrite the callee's edge after this
 * file, or crowd the sink's path budget, and either masks the regression.
 *
 * @psalm-flow ($value) -> return
 */
function frameValue(string $value): string
{
    return $value === '' ? '' : '';
}

/**
 * Content produced directly by a function or static call is never exempted. Psalm dispatches the
 * removal event for that node a second time while fetching the callee's own return type, where a
 * removal lands on the callee's single project-wide argument-to-return edge (`framevalue#1 ->
 * framevalue`): exempting the `make()` call below would silence this unrelated `echo` flow.
 *
 * Order decides WHICH failure this test pins: the edge is last-write-wins, so only with the
 * would-be-exempted call analysed AFTER the unrelated flow does a regression silence the `echo`
 * finding (the cross-flow leak). Reversed, a regression still fails the test, but only through
 * the `make()` call's own missing finding — the local strip, not the leak.
 *
 * Line numbers in the expectation are exact on purpose: with `%d`, an unrelated extra finding
 * could stand in for the silenced flow.
 */
function echoUnrelatedFramedBanner(Request $request): void
{
    echo frameValue((string) $request->input('banner'));
}

function makeAttachmentFromCallContent(Request $request): void
{
    Response::make(frameValue((string) $request->input('blob')), 200, ['Content-Disposition' => 'attachment; filename="export.csv"']);
}
?>
--EXPECTF--
TaintedHtml on line 39: Detected tainted HTML
TaintedTextWithQuotes on line 39: Detected tainted text with possible quotes
TaintedHtml on line 44: Detected tainted HTML

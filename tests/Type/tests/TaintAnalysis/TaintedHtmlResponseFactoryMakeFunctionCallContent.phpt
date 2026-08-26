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
 * The exempted `make()` call below shares this callee's single project-wide argument-to-return
 * edge (`framevalue#1 -> framevalue`). Suppressing at issue-emission time writes nothing to that
 * edge, so this unrelated `echo` flow must keep reporting both of its findings. A regression back
 * to a graph removal keyed on the content node silences them (#1348, vimeo/psalm#11924).
 *
 * Order decides WHICH failure this test pins: the edge would be last-write-wins, so only with the
 * exempted call analysed AFTER the unrelated flow does such a regression silence the `echo`
 * finding — the cross-flow leak rather than a merely local strip.
 *
 * The `%d` placeholders are a repo-wide convention enforced by CI; the single-file batch and
 * the fixed kind order keep an unrelated finding from standing in for a silenced flow.
 */
function echoUnrelatedFramedBanner(Request $request): void
{
    echo frameValue((string) $request->input('banner'));
}

/**
 * Content produced directly by a function call, exempt by its literal attachment headers. This is
 * the false positive the previous FuncCall/StaticCall record gate had to keep.
 */
function makeAttachmentFromCallContent(Request $request): void
{
    Response::make(frameValue((string) $request->input('blob')), 200, ['Content-Disposition' => 'attachment; filename="export.csv"']);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

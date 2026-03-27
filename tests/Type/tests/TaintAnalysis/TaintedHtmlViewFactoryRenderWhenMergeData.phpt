--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function conditionalRenderMergeData(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $title = $request->input('title');
    $factory->renderWhen(true, 'header', [], ['title' => $title]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

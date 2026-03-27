--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderList(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory) {
    $items = $request->all();
    $factory->renderEach('partials.item', $items, 'item');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

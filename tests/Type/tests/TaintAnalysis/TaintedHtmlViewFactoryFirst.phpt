--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderFirst(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $name = $request->input('name');
    $factory->first(['custom', 'default'], ['name' => $name]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

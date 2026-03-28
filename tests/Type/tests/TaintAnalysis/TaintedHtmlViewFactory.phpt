--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderProfile(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory) {
    $name = $request->input('name');
    $factory->make('profile', ['name' => $name]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

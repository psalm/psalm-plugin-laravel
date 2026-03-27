--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderWithArray(\Illuminate\Http\Request $request, \Illuminate\View\View $view): void {
    $name = $request->input('name');
    $view->with(['name' => $name]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML

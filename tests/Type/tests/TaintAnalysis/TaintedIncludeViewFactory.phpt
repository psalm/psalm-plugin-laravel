--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderCustomTemplate(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $template = $request->input('template');
    $factory->file($template);
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderCustomTemplate(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $template = $request->input('template');
    $factory->file($template);
}

// Same call on a contract-typed receiver. Sinks do not cross the interface boundary, so
// this reaches only Contracts/View/Factory.phpstub; it was silent until that stub
// restated file().
function renderCustomTemplateViaContract(\Illuminate\Http\Request $request, \Illuminate\Contracts\View\Factory $factory): void {
    $factory->file((string) $request->input('template'));
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedFile on line %d: Detected tainted file handling
TaintedInclude on line %d: Detected tainted code passed to include or similar

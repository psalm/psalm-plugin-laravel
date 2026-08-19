--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showData(\Illuminate\Http\Request $request): void {
    $items = $request->array('items');

    foreach ($items as $item) {
        echo $item;
        break;
    }
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $item is being assigned to
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function displayWithPrefix(\Illuminate\Http\Request $request): void {
    /** @var string $id */
    $id = $request->input('id');

    echo \Illuminate\Support\Str::of('ID: ')->append($id);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

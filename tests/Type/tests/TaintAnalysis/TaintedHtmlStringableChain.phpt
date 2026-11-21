--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Stringable;

/**
 * A Stringable method whose result holds the string carries its taint through the chain.
 */
function showName(\Illuminate\Http\Request $request): void {
    $name = $request->str('name');

    echo $name->trim()->upper();
    echo $name->after('@')->limit(20);
    echo $name->append('!')->toString();
    echo $name->toHtmlString();
    echo $name->whenNotEmpty(fn (Stringable $s): Stringable => $s)->value();
    echo shout($name);
}

function shout(Stringable $value): string {
    return $value->upper()->value();
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

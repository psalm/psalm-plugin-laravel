--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderOldInput(): void {
    echo old('name');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
PossiblyInvalidArgument on line %d: Argument 1 of echo expects string, but possibly different type array<array-key, mixed>|null|string provided

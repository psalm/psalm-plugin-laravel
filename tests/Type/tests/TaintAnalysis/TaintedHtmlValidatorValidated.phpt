--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** Validator::validated() is a taint source. */
function renderFromValidator(\Illuminate\Validation\Validator $validator): void {
    echo $validator->validated()['body'];
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
PossiblyUndefinedStringArrayOffset on line %d: Possibly undefined array offset ''body'' is risky given expected type 'string'. Consider using isset beforehand.
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Positive: Request::only() returns a tainted array of input values.
 *
 * Regression guard for the stub-location fix (#823): only() lives on
 * Illuminate\Support\Traits\InteractsWithData in Laravel 11+, not on
 * Illuminate\Http\Concerns\InteractsWithInput. If the @psalm-taint-source
 * annotation ends up on the wrong trait, this test stops firing.
 */
function renderOnlyRequestData(\Illuminate\Http\Request $request): void {
    $data = $request->only(['name']);

    echo $data['name'];
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
PossiblyUndefinedStringArrayOffset on line %d: Possibly undefined array offset ''name'' is risky given expected type 'array-key'. Consider using isset beforehand.
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes

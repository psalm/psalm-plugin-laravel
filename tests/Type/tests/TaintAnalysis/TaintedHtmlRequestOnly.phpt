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
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
